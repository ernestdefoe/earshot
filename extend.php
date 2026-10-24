<?php

/*
 * Earshot: podcasts that live in your forum.
 */

use ErnestDefoe\Earshot\Api\Controller;
use ErnestDefoe\Earshot\Api\DiscussionFields;
use ErnestDefoe\Earshot\Console\FetchCommand;
use ErnestDefoe\Earshot\Http\FeedController;
use ErnestDefoe\Earshot\HideProEpisodes;
use ErnestDefoe\Earshot\Http\StylesheetController;
use ErnestDefoe\Earshot\Notification\NewEpisode;
use ErnestDefoe\Earshot\Service\Media;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Extension\Event as ExtensionEvent;
use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->jsDirectory(__DIR__.'/js/dist/forum')
        // Each page route also exists here, so a pasted link loads the page.
        ->route('/podcasts', 'earshot.index')
        ->route('/podcasts/{slug:[a-z0-9-]+}', 'earshot.show'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())->namespace('ernestdefoe-earshot', __DIR__.'/views'),

    // Covers and hosted audio: public files, sent by the web server itself so
    // podcast apps and the player can seek (range requests) without PHP.
    (new Extend\Filesystem())
        ->disk(Media::DISK, fn (Paths $paths, UrlGenerator $url) => [
            'root' => "$paths->public/assets/earshot",
            'url' => $url->to('forum')->path('assets/earshot'),
            // Explicit: left to the umask, directories come out 0700 and every
            // file is a 403 where PHP and the web server are different users.
            'visibility' => 'public',
            'directory_visibility' => 'public',
        ]),

    (new Extend\Routes('forum'))
        ->get('/podcasts/{slug:[a-z0-9-]+}/feed', 'earshot.feed', FeedController::class)
        ->get('/earshot/stylesheet', 'earshot.css', StylesheetController::class),

    (new Extend\Routes('api'))
        ->get('/earshot/shows', 'earshot.shows', Controller\ShowsController::class)
        ->get('/earshot/latest', 'earshot.latest', Controller\LatestController::class)
        ->get('/earshot/shows/{slug:[a-z0-9-]+}', 'earshot.shows.show', Controller\ShowsController::class)
        ->post('/earshot/shows', 'earshot.shows.create', Controller\ShowsController::class)
        ->patch('/earshot/shows/{id:[0-9]+}', 'earshot.shows.update', Controller\ShowsController::class)
        ->delete('/earshot/shows/{id:[0-9]+}', 'earshot.shows.delete', Controller\ShowsController::class)
        ->post('/earshot/shows/{id:[0-9]+}/upload/{kind:cover|audio}', 'earshot.shows.upload', Controller\UploadController::class)
        ->post('/earshot/shows/{id:[0-9]+}/refresh', 'earshot.shows.refresh', Controller\RefreshController::class)
        ->post('/earshot/shows/{id:[0-9]+}/follow', 'earshot.shows.follow', Controller\ListenerController::class)
        ->post('/earshot/shows/{show:[0-9]+}/episodes', 'earshot.episodes.create', Controller\EpisodesController::class)
        ->patch('/earshot/episodes/{id:[0-9]+}', 'earshot.episodes.update', Controller\EpisodesController::class)
        ->delete('/earshot/episodes/{id:[0-9]+}', 'earshot.episodes.delete', Controller\EpisodesController::class)
        ->post('/earshot/progress', 'earshot.progress', Controller\ListenerController::class),

    // Whether to show the navigation link, decided by the server.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canViewEarshot')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('earshot.view')),
        ]),

    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(DiscussionFields::class),

    // Earshot Pro disabled but not purged: its supporters-only and scheduled
    // episodes stay hidden until it is back (fail closed).
    (new Extend\ModelVisibility(Discussion::class))
        ->scope(HideProEpisodes::class),

    (new Extend\Event())
        ->listen(ExtensionEvent\Enabled::class, [HideProEpisodes::class, 'changed'])
        ->listen(ExtensionEvent\Disabled::class, [HideProEpisodes::class, 'changed'])
        ->listen(ExtensionEvent\Uninstalled::class, [HideProEpisodes::class, 'changed']),

    (new Extend\Notification())
        ->type(NewEpisode::class, ['alert', 'email']),

    (new Extend\Console())
        ->command(FetchCommand::class)
        ->schedule(FetchCommand::class, function (Event $event): void {
            $event->everyFifteenMinutes()->withoutOverlapping();
        }),

    (new Extend\Settings())
        ->default('ernestdefoe-earshot.fetch_minutes', '60')
        ->default('ernestdefoe-earshot.backlog', '10')
        ->default('ernestdefoe-earshot.max_audio_mb', '250')
        ->default('ernestdefoe-earshot.nav', '1')
        ->serializeToForum('earshotNav', 'ernestdefoe-earshot.nav', fn ($v) => $v !== '0'),
];
