<?php

namespace ErnestDefoe\Earshot\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Earshot\Api\Concerns;
use ErnestDefoe\Earshot\Api\Presenter;
use ErnestDefoe\Earshot\Job\NotifyFollowers;
use ErnestDefoe\Earshot\Model\Episode;
use ErnestDefoe\Earshot\Model\Show;
use ErnestDefoe\Earshot\Service\FeedReader;
use ErnestDefoe\Earshot\Service\Media;
use ErnestDefoe\Earshot\Service\Starter;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Publishing, changing and removing an episode. Publishing starts its
 * discussion as the host who published it and tells the show's followers.
 */
class EpisodesController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private Presenter $presenter,
        private Media $media,
        private Starter $starter,
        private Queue $queue,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $in = $this->input($request);

        if ($request->getMethod() === 'POST' && $this->param($request, 'show') !== '') {
            $show = $this->editableShow($actor, $this->param($request, 'show'));
            $episode = new Episode();
            $episode->show_id = $show->id;
            $episode->guid = (string) Str::uuid();
            $episode->published_at = Carbon::now();
            $episode->setRelation('show', $show);
        } else {
            $episode = Episode::query()->with('discussion')->findOrFail((int) $this->param($request, 'id'));
            $show = $this->editableShow($actor, $episode->show_id);
            $episode->setRelation('show', $show);
        }

        if ($request->getMethod() === 'DELETE') {
            return $this->delete($episode, $show, $actor);
        }

        return $this->save($episode, $show, $actor, $in);
    }

    /** @param array<string, mixed> $in */
    private function save(Episode $episode, Show $show, User $actor, array $in): ResponseInterface
    {
        $isNew = ! $episode->exists;

        if ($isNew || array_key_exists('title', $in)) {
            $title = FeedReader::line((string) Arr::get($in, 'title', ''), 200);
            if ($title === '') {
                $this->fail('title', 'title_required');
            }
            $episode->title = $title;
        }
        if (array_key_exists('summary', $in)) {
            $episode->summary = mb_substr(trim((string) $in['summary']), 0, 5000) ?: null;
        }
        foreach (['season' => 65535, 'number' => 100000] as $field => $max) {
            if (array_key_exists($field, $in)) {
                $n = (int) $in[$field];
                $episode->$field = $n > 0 && $n <= $max ? $n : null;
            }
        }
        if (array_key_exists('explicit', $in)) {
            $episode->explicit = (bool) $in['explicit'];
        }
        if (array_key_exists('chapters', $in)) {
            $episode->chapters = self::chapters((string) $in['chapters']);
        }
        if (array_key_exists('duration', $in)) {
            $d = (int) $in['duration'];
            $episode->duration = $d > 0 && $d < 86400 ? $d : null;
        }

        // Audio: a file uploaded to this show (path), or an address elsewhere.
        $oldPath = $episode->audio_path;
        if (array_key_exists('audioPath', $in) && (string) $in['audioPath'] !== '' && $in['audioPath'] !== $oldPath) {
            $path = (string) $in['audioPath'];
            if (! preg_match('#^audio/'.$show->id.'/[A-Za-z0-9]{32}\.(mp3|m4a|ogg)$#', $path)
                || ! $this->media->disk()->exists($path)
                || Episode::query()->where('audio_path', $path)->exists()) {
                $this->fail('audio', 'audio_invalid');
            }
            $episode->audio_path = $path;
            $episode->audio_url = null;
            $episode->audio_type = match (pathinfo($path, PATHINFO_EXTENSION)) {
                'm4a' => 'audio/mp4',
                'ogg' => 'audio/ogg',
                default => 'audio/mpeg',
            };
            $episode->audio_bytes = (int) $this->media->disk()->size($path);
        } elseif (array_key_exists('audioUrl', $in) && (string) $in['audioUrl'] !== '') {
            $url = FeedReader::url((string) $in['audioUrl']);
            if ($url === null) {
                $this->fail('audio', 'audio_invalid');
            }
            $episode->audio_url = $url;
            $episode->audio_path = null;
            $episode->audio_type = self::typeFor($url);
            $episode->audio_bytes = null;
        }
        if (! $episode->audio_path && ! $episode->audio_url) {
            $this->fail('audio', 'audio_required');
        }

        $episode->save();
        if ($oldPath && $oldPath !== $episode->audio_path) {
            $this->media->delete($oldPath);
        }

        if ($isNew) {
            $this->starter->start($episode, $actor);
            $show->refreshCounts();
            $show->save();
            $this->queue->push(new NotifyFollowers($episode->id));
        } else {
            $this->starter->rename($episode, $actor);
        }

        $episode->load('discussion:id,slug,comment_count');

        return new JsonResponse(['episode' => $this->presenter->episode($episode, null, true)]);
    }

    private function delete(Episode $episode, Show $show, User $actor): ResponseInterface
    {
        $this->starter->hide($episode, $actor);
        $episode->delete();
        $this->media->delete($episode->audio_path);
        $show->refreshCounts();
        $show->save();

        return new EmptyResponse(204);
    }

    /**
     * Chapters as hosts type them, one a line: "12:34 The title" or
     * "1:02:03 The title". Lines that do not start with a time are skipped.
     *
     * @return list<array{0: int, 1: string}>|null
     */
    public static function chapters(string $text): ?array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\s*((?:\d+:)?\d{1,2}:\d{2})\s*[-–—:.]?\s*(.+)$/u', $line, $m)) {
                $seconds = FeedReader::duration($m[1]) ?? 0;
                $title = FeedReader::line($m[2], 120);
                if ($title !== '') {
                    $out[] = [$seconds, $title];
                }
            }
        }
        usort($out, fn ($a, $b) => $a[0] <=> $b[0]);

        return $out ? array_slice($out, 0, 200) : null;
    }

    private static function typeFor(string $url): string
    {
        return match (strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION))) {
            'm4a', 'mp4', 'aac' => 'audio/mp4',
            'ogg', 'oga', 'opus' => 'audio/ogg',
            default => 'audio/mpeg',
        };
    }
}
