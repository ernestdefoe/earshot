<?php

namespace ErnestDefoe\Earshot\Http;

use ErnestDefoe\Earshot\Model\Episode;
use ErnestDefoe\Earshot\Model\Show;
use ErnestDefoe\Earshot\Service\FeedReader;
use ErnestDefoe\Earshot\Service\Media;
use Flarum\Http\UrlGenerator;
use Flarum\User\Guest;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A hosted show's podcast feed: RSS 2.0 with the itunes: and podcast:
 * namespaces, so Apple Podcasts, Spotify and every podcast app can list it.
 *
 * Built as a guest sees the forum (podcast apps never sign in), so an episode
 * in a members-only tag never appears here. A followed show's feed lives on
 * its own host: this address sends apps there.
 *
 * 🚨 No ".xml" on the address: nginx answers *.xml itself on a stock Flarum
 * config, and the request would never reach PHP.
 */
class FeedController implements RequestHandlerInterface
{
    public const LIMIT = 300;

    public function __construct(private UrlGenerator $url, private Media $media)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guest = new Guest();
        if (! $guest->hasPermission('earshot.view')) {
            return new EmptyResponse(404);
        }

        $show = Show::query()->with('user')->where('slug', (string) Arr::get($request->getQueryParams(), 'slug'))->firstOrFail();
        if ($show->follows()) {
            return new RedirectResponse((string) $show->feed_url, 301);
        }

        $episodes = Episode::query()
            ->visibleTo($guest)
            ->where('show_id', $show->id)
            ->with('discussion:id,slug')
            ->orderByDesc('published_at')
            ->limit(self::LIMIT)
            ->get();

        $body = $this->render($show, $episodes);
        $etag = '"'.md5($body).'"';
        if (trim((string) $request->getHeaderLine('If-None-Match')) === $etag) {
            return new EmptyResponse(304, ['ETag' => $etag]);
        }

        $response = new Response('php://memory', 200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
            'ETag' => $etag,
        ]);
        $response->getBody()->write($body);

        return $response;
    }

    /** @param \Illuminate\Support\Collection<int, Episode> $episodes */
    public function render(Show $show, $episodes): string
    {
        $forum = $this->url->to('forum');
        $page = $forum->route('earshot.show', ['slug' => $show->slug]);
        $cover = $this->media->url($show->cover_path) ?? $show->cover_url;

        $x = new \XMLWriter();
        $x->openMemory();
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('rss');
        $x->writeAttribute('version', '2.0');
        $x->writeAttribute('xmlns:itunes', FeedReader::ITUNES);
        $x->writeAttribute('xmlns:podcast', FeedReader::PODCAST);
        $x->writeAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $x->startElement('channel');

        $x->writeElement('title', $show->title);
        $x->writeElement('link', $page);
        $x->startElement('atom:link');
        $x->writeAttribute('href', $forum->route('earshot.feed', ['slug' => $show->slug]));
        $x->writeAttribute('rel', 'self');
        $x->writeAttribute('type', 'application/rss+xml');
        $x->endElement();
        $x->writeElement('description', $show->description ?: ($show->tagline ?: $show->title));
        $x->writeElement('language', $show->language);
        $x->writeElement('generator', 'Earshot for Flarum');
        if ($show->user) {
            $x->writeElement('itunes:author', $show->user->display_name);
        }
        if ($show->tagline) {
            $x->writeElement('itunes:subtitle', $show->tagline);
        }
        $x->writeElement('itunes:explicit', $show->explicit ? 'true' : 'false');
        $x->writeElement('itunes:type', 'episodic');
        if ($cover) {
            $x->startElement('itunes:image');
            $x->writeAttribute('href', $cover);
            $x->endElement();
            $x->startElement('image');
            $x->writeElement('url', $cover);
            $x->writeElement('title', $show->title);
            $x->writeElement('link', $page);
            $x->endElement();
        }
        if ($show->category) {
            $x->startElement('itunes:category');
            $x->writeAttribute('text', $show->category);
            $x->endElement();
        }
        $x->writeElement('podcast:guid', self::podcastGuid($page));

        foreach ($episodes as $e) {
            $audio = $this->media->url($e->audio_path) ?? $e->audio_url;
            if (! $audio) {
                continue;
            }
            $x->startElement('item');
            $x->writeElement('title', $e->title);
            $x->startElement('guid');
            $x->writeAttribute('isPermaLink', 'false');
            $x->text($e->guid);
            $x->endElement();
            if ($e->discussion) {
                $x->writeElement('link', $forum->route('discussion', ['id' => $e->discussion->id.($e->discussion->slug ? '-'.$e->discussion->slug : '')]));
            }
            $x->writeElement('pubDate', $e->published_at->toRfc2822String());
            if ($e->summary) {
                $x->writeElement('description', $e->summary);
            }
            $x->startElement('enclosure');
            $x->writeAttribute('url', $audio);
            $x->writeAttribute('length', (string) ($e->audio_bytes ?? 0));
            $x->writeAttribute('type', $e->audio_type ?: 'audio/mpeg');
            $x->endElement();
            if ($e->duration) {
                $x->writeElement('itunes:duration', (string) $e->duration);
            }
            if ($e->season) {
                $x->writeElement('itunes:season', (string) $e->season);
            }
            if ($e->number) {
                $x->writeElement('itunes:episode', (string) $e->number);
            }
            $x->writeElement('itunes:explicit', $e->explicit ? 'true' : 'false');
            $x->endElement();
        }

        $x->endElement();
        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    /**
     * The Podcasting 2.0 GUID: a UUIDv5 of the feed's address without its
     * scheme, under the namespace the podcast: spec fixes.
     */
    public static function podcastGuid(string $url): string
    {
        $name = rtrim((string) preg_replace('#^[a-z]+://#i', '', $url), '/');
        $ns = (string) hex2bin(str_replace('-', '', 'ead4c236-bf58-58c6-a2c6-a6b28d128cb6'));
        $hash = sha1($ns.$name);
        $hash = substr($hash, 0, 12).'5'.substr($hash, 13, 3).dechex((hexdec($hash[16]) & 0x3) | 0x8).substr($hash, 17, 15);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hash, 4));
    }
}
