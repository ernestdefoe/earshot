<?php

namespace ErnestDefoe\Earshot;

use Carbon\Carbon;
use Flarum\Extension\Event\Disabled;
use Flarum\Extension\Event\Enabled;
use Flarum\Extension\Event\Uninstalled;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;

/**
 * Earshot Pro disabled but not purged leaves its columns on earshot_episodes.
 * Without Pro's own scope, a supporters-only or scheduled episode would then
 * be readable by everyone who can see the show: lists, search, the episode,
 * the public feed. Until Pro is back, those stay hidden (fail closed); with Pro
 * enabled this does nothing and Pro's rules apply.
 *
 * Costs no query: whether Pro is enabled comes from the settings core has
 * loaded, and whether its columns exist is remembered in the cache (forgotten
 * when Pro is enabled, disabled or purged, and by cache:clear).
 */
class HideProEpisodes
{
    public const PRO = 'ernestdefoe-earshot-pro';

    public const KEY = 'ernestdefoe-earshot.pro-columns';

    /** @var array{0: bool, 1: int}|null The cached answer and when it was read, so a long-running worker notices a purge. */
    private static ?array $memo = null;

    public function __construct(private ExtensionManager $extensions, private Cache $cache)
    {
    }

    /** @param Builder<\Flarum\Discussion\Discussion> $query */
    public function __invoke(User $actor, Builder $query): void
    {
        if ($this->extensions->isEnabled(self::PRO) || ! $this->proColumns($query) || $actor->hasPermission('earshot.manage')) {
            return;
        }

        $table = $query->getModel()->getTable();
        $query->whereNotExists(fn ($q) => $q->selectRaw('1')
            ->from('earshot_episodes')
            ->whereColumn('earshot_episodes.discussion_id', "$table.id")
            ->where(fn ($w) => $w->whereNotNull('earshot_episodes.audience_group_id')
                ->orWhere('earshot_episodes.public_at', '>', Carbon::now())));
    }

    /** @param Builder<\Flarum\Discussion\Discussion> $query */
    private function proColumns(Builder $query): bool
    {
        if (self::$memo === null || self::$memo[1] < time() - 60) {
            $schema = $query->getConnection()->getSchemaBuilder();
            self::$memo = [(bool) $this->cache->rememberForever(self::KEY, fn () => (int) $schema->hasColumns('earshot_episodes', ['audience_group_id', 'public_at'])), time()];
        }

        return self::$memo[0];
    }

    /** Pro enabled, disabled or purged: its columns may have come or gone. */
    public function changed(Enabled|Disabled|Uninstalled $event): void
    {
        if ($event->extension->getId() === self::PRO) {
            self::$memo = null;
            $this->cache->forget(self::KEY);
        }
    }
}
