<?php

namespace ErnestDefoe\Earshot\Api\Controller;

use ErnestDefoe\Earshot\Api\Concerns;
use ErnestDefoe\Earshot\Api\Presenter;
use ErnestDefoe\Earshot\Model\Episode;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The newest episodes across every show, for the Latest episodes widget. Four queries, whatever the limit. */
class LatestController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $limit = min(10, max(1, (int) ($this->param($request, 'limit') ?: 5)));

        $episodes = Episode::query()
            ->visibleTo($actor)
            ->with(['show', 'discussion:id,slug,comment_count'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $shows = [];
        foreach ($episodes as $e) {
            $shows[$e->show_id] ??= $this->presenter->brief($e->show);
        }

        return new JsonResponse([
            'episodes' => $this->presenter->episodes($episodes, $actor),
            'shows' => (object) $shows,
        ]);
    }
}
