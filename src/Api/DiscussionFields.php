<?php

namespace ErnestDefoe\Earshot\Api;

use ErnestDefoe\Earshot\Model\Episode;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;

/**
 * The episode behind a discussion, for the hero and player on its page. Sent
 * when a single discussion is shown, never in lists: a few queries on that
 * page, none anywhere else.
 */
class DiscussionFields
{
    public function __construct(private Presenter $presenter)
    {
    }

    /** @return array<int, Schema\Arr> */
    public function __invoke(): array
    {
        return [
            Schema\Arr::make('earshot')
                ->nullable()
                ->visible(fn (Discussion $d, Context $context) => $context->showing(DiscussionResource::class))
                ->get(function (Discussion $d, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor->hasPermission('earshot.view')) {
                        return null;
                    }
                    $episode = Episode::query()->with('show.hosts')->where('discussion_id', $d->id)->first();
                    if (! $episode) {
                        return null;
                    }
                    $episode->setRelation('discussion', $d);
                    $progress = $this->presenter->progress($actor, [$episode->id]);

                    return [
                        'show' => $this->presenter->show($episode->show, $actor) + ['canEdit' => $episode->show->editableBy($actor)],
                        'episode' => $this->presenter->episode($episode, $progress[$episode->id] ?? null, true),
                    ];
                }),
        ];
    }
}
