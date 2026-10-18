<?php

namespace ErnestDefoe\Earshot\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Earshot\Api\Concerns;
use ErnestDefoe\Earshot\Model\Show;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A member's own listening: where they stopped in an episode (sent by the
 * player every so often and on pause), and which shows they follow.
 */
class ListenerController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $actor->assertRegistered();
        $in = $this->input($request);

        if ($this->param($request, 'id') !== '') {
            $actor->assertPermission($actor->hasPermission('earshot.follow'));
            $show = Show::query()->findOrFail((int) $this->param($request, 'id'));
            $key = ['user_id' => $actor->id, 'show_id' => $show->id];
            if (Arr::get($in, 'follow', true)) {
                $this->db->table('earshot_follows')->insertOrIgnore($key + ['created_at' => Carbon::now()]);
            } else {
                $this->db->table('earshot_follows')->where($key)->delete();
            }
            $show->refreshCounts();
            $show->save();

            return new JsonResponse(['following' => (bool) Arr::get($in, 'follow', true), 'followerCount' => $show->follower_count]);
        }

        // Progress. Saying where you stopped in an episode reveals nothing,
        // so an existing id is all that is checked: one cheap query.
        $episode = (int) Arr::get($in, 'episode');
        if (! $this->db->table('earshot_episodes')->where('id', $episode)->exists()) {
            return new EmptyResponse(204);
        }
        $this->db->table('earshot_progress')->upsert(
            [[
                'user_id' => $actor->id,
                'episode_id' => $episode,
                'position' => max(0, min(86400, (int) Arr::get($in, 'position'))),
                'finished' => (bool) Arr::get($in, 'finished', false),
                'updated_at' => Carbon::now(),
            ]],
            ['user_id', 'episode_id'],
            ['position', 'finished', 'updated_at']
        );

        return new EmptyResponse(204);
    }
}
