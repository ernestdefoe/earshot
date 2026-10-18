<?php

namespace ErnestDefoe\Earshot\Service;

use Carbon\Carbon;
use ErnestDefoe\Earshot\Job\NotifyFollowers;
use ErnestDefoe\Earshot\Model\Episode;
use ErnestDefoe\Earshot\Model\Show;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;

/**
 * Follow mode: a show whose audio and feed live on another host (Buzzsprout,
 * Transistor, Anchor...). Its feed is read, and each new item becomes an
 * episode with its own discussion.
 *
 * Every fetch is capped: one feed request (conditional, so an unchanged feed
 * costs a 304), and at most CHAPTER_FETCHES chapter files per run.
 */
class Follower
{
    public const FEED_BYTES = 40_000_000;
    public const PER_RUN = 25;
    public const CHAPTER_FETCHES = 5;

    /** The title the last feed read gave, for a show started without one. */
    public ?string $lastTitle = null;

    public function __construct(
        private SafeHttp $http,
        private FeedReader $reader,
        private Starter $starter,
        private SettingsRepositoryInterface $settings,
        private Queue $queue,
        private Media $media,
    ) {
    }

    /**
     * Reads the show's feed. Returns the number of new episodes, or an error
     * key (also kept on the show for the admin to see).
     */
    public function refresh(Show $show, User $fallback, bool $force = false): int|string
    {
        if (! $show->follows()) {
            return 0;
        }

        $headers = [];
        if (! $force && $show->feed_etag) {
            $headers[] = 'If-None-Match: '.$show->feed_etag;
        }
        if (! $force && $show->feed_modified) {
            $headers[] = 'If-Modified-Since: '.$show->feed_modified;
        }

        $response = $this->http->get((string) $show->feed_url, self::FEED_BYTES, 15, $headers);
        $show->fetched_at = Carbon::now();

        if (isset($response['error'])) {
            return $this->failed($show, $response['error']);
        }
        if ($response['status'] === 304) {
            $show->fetch_error = null;
            $show->save();

            return 0;
        }
        if ($response['status'] >= 400) {
            return $this->failed($show, 'http_'.$response['status']);
        }

        $feed = $this->reader->read($response['body']);
        if ($feed === null) {
            return $this->failed($show, 'not_a_feed');
        }

        $this->lastTitle = $feed['show']['title'] ?: null;
        $show->feed_etag = $response['etag'] ?: null;
        $show->feed_modified = $response['modified'] ?: null;
        $show->fetch_error = null;
        $this->fillShow($show, $feed['show']);
        $show->save();

        return $this->import($show, $feed['items'], $fallback);
    }

    /**
     * New items into episodes, oldest first so their discussions sort the way
     * they were released. A first import brings in only the latest few and
     * tells nobody; later ones notify the show's followers.
     *
     * @param list<array<string, mixed>> $items
     */
    public function import(Show $show, array $items, User $fallback): int
    {
        $first = ! $show->episodes()->exists();
        $known = $show->episodes()->pluck('guid')->flip();

        // After the first import, only what is newer than the newest episode
        // here: the backlog a first import left behind stays behind.
        $newest = $first ? null : $show->episodes()->max('published_at');
        $newest = $newest ? Carbon::parse($newest) : null;
        $new = array_values(array_filter($items, fn ($i) => ! isset($known[$i['guid']]) && (! $newest || ! $i['published'] || $i['published']->gt($newest))));
        usort($new, fn ($a, $b) => ($b['published'] ?? Carbon::now()) <=> ($a['published'] ?? Carbon::now()));
        $limit = $first ? max(1, min(self::PER_RUN, (int) ($this->settings->get('ernestdefoe-earshot.backlog') ?: 10))) : self::PER_RUN;
        $new = array_reverse(array_slice($new, 0, $limit));

        $author = $show->user ?? $fallback;
        $chapterFetches = 0;
        $created = 0;

        foreach ($new as $item) {
            $episode = new Episode();
            $episode->show_id = $show->id;
            $episode->guid = $item['guid'];
            $episode->title = $item['title'];
            $episode->summary = $item['summary'] ?: null;
            $episode->audio_url = $item['audio'];
            $episode->audio_type = $item['type'];
            $episode->audio_bytes = $item['bytes'];
            $episode->duration = $item['duration'];
            $episode->season = $item['season'];
            $episode->number = $item['number'];
            $episode->image_url = $item['image'];
            $episode->explicit = $item['explicit'] || $show->explicit;
            // A future date would hide it until then; a feed's clock is not ours.
            $episode->published_at = $item['published'] && $item['published']->isPast() ? $item['published'] : Carbon::now();

            if ($item['chapters'] && $chapterFetches < self::CHAPTER_FETCHES) {
                $chapterFetches++;
                $episode->chapters = $this->chapters($item['chapters']);
            }

            $episode->save();
            $episode->setRelation('show', $show);
            $this->starter->start($episode, $author);
            $created++;

            if (! $first) {
                $this->queue->push(new NotifyFollowers($episode->id));
            }
        }

        if ($created) {
            $show->refreshCounts();
            $show->save();
        }

        return $created;
    }

    /** @return list<array{0: int, 1: string}>|null */
    public function chapters(string $url): ?array
    {
        $response = $this->http->get($url, 256_000, 5);

        return isset($response['body']) && $response['status'] < 400 ? FeedReader::chapters($response['body']) : null;
    }

    /**
     * The feed's own details fill what the show has not been given: an edit on
     * the forum is never overwritten by the next fetch.
     *
     * @param array<string, mixed> $meta
     */
    private function fillShow(Show $show, array $meta): void
    {
        if (! $show->description && $meta['description']) {
            $show->description = $meta['description'];
        }
        // The feed's artwork, fetched once and kept here: hosts send 3000 px
        // originals, and visitors' browsers never call the podcast host's
        // CDN for a picture. A cover uploaded on the forum always wins.
        if ($meta['image'] && $meta['image'] !== $show->cover_url && (! $show->cover_path || str_starts_with($show->cover_path, "covers/$show->id/feed-"))) {
            $response = $this->http->get($meta['image'], 15_000_000, 10);
            $path = isset($response['body']) && $response['status'] < 400 ? $this->media->storeCoverBytes($response['body'], $show->id) : null;
            if ($path) {
                $this->media->deleteCover($show->cover_path);
                // Named so the next read knows it came from the feed.
                $this->media->disk()->move($path, $feedPath = "covers/$show->id/feed-".basename($path));
                $this->media->disk()->move(Media::small($path), Media::small($feedPath));
                $show->cover_path = $feedPath;
            }
            $show->cover_url = $meta['image'];
        }
        if (! $show->category && $meta['category']) {
            $show->category = $meta['category'];
        }
        if ($meta['explicit']) {
            $show->explicit = true;
        }
    }

    private function failed(Show $show, string $error): string
    {
        $show->fetch_error = mb_substr($error, 0, 60);
        $show->save();

        return $error;
    }
}
