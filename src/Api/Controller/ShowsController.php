<?php

namespace ErnestDefoe\Earshot\Api\Controller;

use ErnestDefoe\Earshot\Api\Concerns;
use ErnestDefoe\Earshot\Api\Presenter;
use ErnestDefoe\Earshot\Model\Episode;
use ErnestDefoe\Earshot\Model\Show;
use ErnestDefoe\Earshot\Service\FeedReader;
use ErnestDefoe\Earshot\Service\Follower;
use ErnestDefoe\Earshot\Service\Media;
use ErnestDefoe\Earshot\Service\SafeHttp;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Shows: the /podcasts index, one show's page with a page of its episodes,
 * and creating, changing and removing a show.
 *
 * The index is two queries whatever the number of shows; a show's page is a
 * fixed handful whatever the number of episodes.
 */
class ShowsController implements RequestHandlerInterface
{
    use Concerns;

    public const PER_PAGE = 20;
    public const MAX_HOSTS = 8;

    public function __construct(
        private Presenter $presenter,
        private Media $media,
        private Follower $follower,
        private SafeHttp $http,
        private ExtensionManager $extensions,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $id = $this->param($request, 'id');

        return match ($request->getMethod()) {
            'GET' => $this->param($request, 'slug') !== '' ? $this->one($request, $actor) : $this->index($actor),
            'DELETE' => $this->delete($actor, $id),
            default => $this->save($request, $actor, $id),
        };
    }

    private function index(User $actor): ResponseInterface
    {
        $shows = Show::query()->with('hosts')->orderByRaw('last_episode_at IS NULL')->orderByDesc('last_episode_at')->orderBy('title')->get();

        return new JsonResponse([
            'shows' => $shows->map(fn (Show $s) => $this->presenter->show($s, $actor))->values()->all(),
            'canCreate' => $actor->hasPermission('earshot.manage'),
        ]);
    }

    private function one(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $show = Show::query()->with('hosts')->where('slug', $this->param($request, 'slug'))->firstOrFail();
        $page = min(500, max(0, (int) $this->param($request, 'page')));

        $episodes = Episode::query()
            ->visibleTo($actor)
            ->where('show_id', $show->id)
            ->with('discussion:id,slug,comment_count')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->offset($page * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get();

        $more = $episodes->count() > self::PER_PAGE;

        return new JsonResponse([
            'show' => $this->presenter->show($show, $actor, true),
            'episodes' => $this->presenter->episodes($episodes->take(self::PER_PAGE), $actor),
            'more' => $more,
        ]);
    }

    private function save(ServerRequestInterface $request, User $actor, string $id): ResponseInterface
    {
        $in = $this->input($request);
        $manager = $actor->hasPermission('earshot.manage');

        if ($id !== '') {
            $show = $this->editableShow($actor, $id);
        } else {
            $actor->assertPermission($manager);
            $show = new Show();
            $show->user_id = $actor->id;
        }

        if (! $show->exists || array_key_exists('title', $in)) {
            $title = FeedReader::line((string) Arr::get($in, 'title', ''), 120);
            if ($title === '') {
                $this->fail('title', 'title_required');
            }
            $show->title = $title;
        }

        if (! $show->exists || array_key_exists('slug', $in)) {
            $slug = Str::slug((string) (Arr::get($in, 'slug') ?: $show->title)) ?: 'show';
            $slug = mb_substr($slug, 0, 70);
            $base = $slug;
            for ($n = 2; Show::query()->where('slug', $slug)->whereKeyNot($show->id ?? 0)->exists(); $n++) {
                $slug = "$base-$n";
            }
            $show->slug = $slug;
        }

        foreach (['tagline' => 200, 'category' => 80] as $field => $max) {
            if (array_key_exists($field, $in)) {
                $show->$field = FeedReader::line((string) $in[$field], $max) ?: null;
            }
        }
        if (array_key_exists('description', $in)) {
            $show->description = mb_substr(trim((string) $in['description']), 0, 4000) ?: null;
        }
        if (array_key_exists('language', $in)) {
            $lang = strtolower(trim((string) $in['language']));
            $show->language = preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $lang) ? $lang : 'en';
        }
        if (array_key_exists('color', $in)) {
            $color = trim((string) $in['color']);
            $show->color = preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : null;
        }
        if (array_key_exists('explicit', $in)) {
            $show->explicit = (bool) $in['explicit'];
        }
        if (array_key_exists('links', $in) && is_array($in['links'])) {
            $links = [];
            foreach (Show::LINKS as $key) {
                $url = FeedReader::url((string) ($in['links'][$key] ?? ''));
                if ($url !== null) {
                    $links[$key] = mb_substr($url, 0, 500);
                }
            }
            $show->links = $links ?: null;
        }

        // Where discussions start and whose feed it reads: moderators' calls,
        // since a tag can carry permissions a host may not have.
        $feedChanged = false;
        if ($manager) {
            if (array_key_exists('tagId', $in)) {
                $tag = (int) $in['tagId'];
                $show->tag_id = $tag && $this->extensions->isEnabled('flarum-tags') && \Flarum\Tags\Tag::query()->whereKey($tag)->exists() ? $tag : null;
            }
            if (array_key_exists('feedUrl', $in)) {
                $feed = trim((string) $in['feedUrl']);
                if ($feed !== '' && (FeedReader::url($feed) === null || is_string($this->http->check($feed)))) {
                    $this->fail('feedUrl', 'feed_invalid');
                }
                $feedChanged = $feed !== (string) $show->feed_url;
                $show->feed_url = $feed ?: null;
                if ($feedChanged) {
                    $show->feed_etag = $show->feed_modified = null;
                }
            }
        }

        $show->save();

        // Hosts by username, in the order given.
        if ($manager && array_key_exists('hosts', $in) && is_array($in['hosts'])) {
            $names = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n, " @\t"), array_slice($in['hosts'], 0, self::MAX_HOSTS)))));
            $found = User::query()->whereIn('username', $names)->pluck('id', 'username');
            $missing = array_diff($names, $found->keys()->all());
            if ($missing) {
                $this->fail('hosts', 'host_missing', ['{username}' => reset($missing)]);
            }
            $sync = [];
            foreach ($names as $i => $name) {
                $sync[$found[$name]] = ['position' => $i];
            }
            $show->hosts()->sync($sync);
            $show->load('hosts');
        }

        // A feed just set is read at once, so the page fills while they watch.
        if ($feedChanged && $show->follows()) {
            $result = $this->follower->refresh($show, $actor, true);
            if (is_string($result)) {
                $show->refresh();
            }
        }

        $show->loadMissing('hosts');

        return new JsonResponse(['show' => $this->presenter->show($show, $actor, true)]);
    }

    /**
     * The show and its episode rows go; their discussions stay, as ordinary
     * discussions, so no conversation is lost with it.
     */
    private function delete(User $actor, string $id): ResponseInterface
    {
        $actor->assertPermission($actor->hasPermission('earshot.manage'));
        $show = Show::query()->findOrFail((int) $id);

        $files = Episode::query()->where('show_id', $show->id)->whereNotNull('audio_path')->pluck('audio_path')->all();
        $show->delete();
        foreach ([...$files, $show->cover_path] as $path) {
            $this->media->delete($path);
        }

        return new EmptyResponse(204);
    }
}
