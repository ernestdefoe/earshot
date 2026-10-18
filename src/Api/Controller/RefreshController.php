<?php

namespace ErnestDefoe\Earshot\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Earshot\Api\Concerns;
use ErnestDefoe\Earshot\Api\Presenter;
use ErnestDefoe\Earshot\Service\Follower;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** "Check the feed now", for a followed show's hosts. */
class RefreshController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Follower $follower, private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $show = $this->editableShow($actor, $this->param($request, 'id'));

        // Once a minute at most: each read can download a large feed.
        $result = $show->fetched_at && $show->fetched_at->gt(Carbon::now()->subMinute())
            ? 0
            : $this->follower->refresh($show, $actor, true);

        return new JsonResponse([
            'added' => is_int($result) ? $result : 0,
            'error' => is_string($result) ? $result : null,
            'show' => $this->presenter->show($show->fresh(['hosts']) ?? $show, $actor, true),
        ]);
    }
}
