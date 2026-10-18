<?php

namespace ErnestDefoe\Earshot\Api;

use ErnestDefoe\Earshot\Model\Episode;
use ErnestDefoe\Earshot\Model\Show;
use ErnestDefoe\Earshot\Service\Media;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Shows and episodes as the forum's pages read them. Everything a page needs
 * arrives in one response, built from relations loaded up front: nothing here
 * runs a query per row.
 */
class Presenter
{
    public function __construct(
        private Media $media,
        private UrlGenerator $url,
        private ConnectionInterface $db,
    ) {
    }

    /** @return array<string, mixed> */
    public function show(Show $show, User $actor, bool $full = false): array
    {
        $data = [
            'id' => $show->id,
            'slug' => $show->slug,
            'title' => $show->title,
            'tagline' => $show->tagline,
            'cover' => $this->media->url($show->cover_path) ?? $show->cover_url,
            'coverSmall' => $show->cover_path ? $this->media->url(Media::small($show->cover_path)) : $show->cover_url,
            'color' => $show->color,
            'explicit' => $show->explicit,
            'episodeCount' => $show->episode_count,
            'followerCount' => $show->follower_count,
            'lastEpisodeAt' => $show->last_episode_at?->toIso8601String(),
            'hosts' => $show->hosts->map(fn (User $u) => $this->user($u))->values()->all(),
        ];

        if ($full) {
            $data += [
                'description' => $show->description,
                'language' => $show->language,
                'category' => $show->category,
                'links' => (object) array_intersect_key($show->links ?? [], array_flip(Show::LINKS)),
                'follows' => $show->follows(),
                'feedUrl' => $show->follows() ? $show->feed_url : $this->url->to('forum')->route('earshot.feed', ['slug' => $show->slug]),
                'tagId' => $show->tag_id,
                'canEdit' => $show->editableBy($actor),
                'canManage' => $actor->hasPermission('earshot.manage'),
                'following' => $actor->exists && $this->db->table('earshot_follows')->where('user_id', $actor->id)->where('show_id', $show->id)->exists(),
                'canFollow' => $actor->hasPermission('earshot.follow'),
                'played' => $actor->exists ? $this->db->table('earshot_progress')
                    ->join('earshot_episodes', 'earshot_episodes.id', '=', 'earshot_progress.episode_id')
                    ->where('earshot_progress.user_id', $actor->id)
                    ->where('earshot_episodes.show_id', $show->id)
                    ->where('earshot_progress.finished', true)
                    ->count() : 0,
            ];
            if ($data['canEdit']) {
                $data['fetchedAt'] = $show->fetched_at?->toIso8601String();
                $data['fetchError'] = $show->fetch_error;
            }
        }

        return $data;
    }

    /** @return array<string, mixed> A show as a card or the player needs it, without its hosts. */
    public function brief(Show $show): array
    {
        return [
            'id' => $show->id,
            'slug' => $show->slug,
            'title' => $show->title,
            'cover' => $this->media->url($show->cover_path) ?? $show->cover_url,
            'coverSmall' => $show->cover_path ? $this->media->url(Media::small($show->cover_path)) : $show->cover_url,
            'color' => $show->color,
        ];
    }

    /**
     * @param Collection<int, Episode> $episodes
     * @return list<array<string, mixed>>
     */
    public function episodes(Collection $episodes, User $actor, bool $full = false): array
    {
        $progress = $this->progress($actor, $episodes->pluck('id')->all());

        return $episodes->map(fn (Episode $e) => $this->episode($e, $progress[$e->id] ?? null, $full))->values()->all();
    }

    /**
     * @param array{position: int, finished: bool}|null $progress
     * @return array<string, mixed>
     */
    public function episode(Episode $e, ?array $progress, bool $full = false): array
    {
        $data = [
            'id' => $e->id,
            'showId' => $e->show_id,
            'discussionId' => $e->discussion_id,
            'slug' => $e->discussion?->slug,
            'replies' => max(0, (int) $e->discussion?->comment_count - 1),
            'title' => $e->title,
            'season' => $e->season,
            'number' => $e->number,
            'audio' => $this->media->url($e->audio_path) ?? $e->audio_url,
            'type' => $e->audio_type,
            'duration' => $e->duration,
            'image' => $e->image_url,
            'explicit' => $e->explicit,
            'publishedAt' => $e->published_at->toIso8601String(),
            'progress' => $progress,
        ];
        if ($full) {
            $data['chapters'] = $e->chapters ?? [];
            $data['bytes'] = $e->audio_bytes;
            $data['summary'] = $e->summary;
        }

        return $data;
    }

    /**
     * Where this member stopped in each of these episodes, in one query.
     *
     * @param list<int> $ids
     * @return array<int, array{position: int, finished: bool}>
     */
    public function progress(User $actor, array $ids): array
    {
        if ($actor->isGuest() || ! $ids) {
            return [];
        }

        return $this->db->table('earshot_progress')
            ->where('user_id', $actor->id)
            ->whereIn('episode_id', $ids)
            ->get(['episode_id', 'position', 'finished'])
            ->mapWithKeys(fn ($r) => [(int) $r->episode_id => ['position' => (int) $r->position, 'finished' => (bool) $r->finished]])
            ->all();
    }

    /** @return array<string, mixed> */
    public function user(User $u): array
    {
        return [
            'id' => $u->id,
            'username' => $u->username,
            'displayName' => $u->display_name,
            'avatarUrl' => $u->avatar_url,
            'slug' => $u->slug ?? $u->username,
        ];
    }
}
