<?php

namespace ErnestDefoe\Earshot\Console;

use Carbon\Carbon;
use ErnestDefoe\Earshot\Model\Show;
use ErnestDefoe\Earshot\Service\Follower;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Console\Command;

/**
 * Reads followed shows' feeds. Scheduled every 15 minutes; each show is read
 * at most once per the forum's interval, oldest first, and a run reads at most
 * BATCH feeds, so a forum following many shows spreads them out.
 */
class FetchCommand extends Command
{
    public const BATCH = 20;

    protected $signature = 'earshot:fetch {--show= : Read one show now, by id or slug}';

    protected $description = 'Read the feeds of followed podcasts and add their new episodes.';

    public function handle(Follower $follower, SettingsRepositoryInterface $settings): int
    {
        $fallback = User::query()->whereHas('groups', fn ($q) => $q->where('groups.id', Group::ADMINISTRATOR_ID))->orderBy('id')->first();
        if (! $fallback) {
            $this->error('No administrator to start discussions as.');

            return self::FAILURE;
        }

        $query = Show::query()->with('user')->whereNotNull('feed_url')->where('feed_url', '!=', '');
        if ($one = $this->option('show')) {
            $query->where(fn ($q) => $q->where('id', (int) $one)->orWhere('slug', (string) $one));
        } else {
            $minutes = max(15, (int) ($settings->get('ernestdefoe-earshot.fetch_minutes') ?: 60));
            $query->where(fn ($q) => $q->whereNull('fetched_at')->orWhere('fetched_at', '<', Carbon::now()->subMinutes($minutes)))
                ->orderByRaw('fetched_at IS NOT NULL')->orderBy('fetched_at')->limit(self::BATCH);
        }

        foreach ($query->get() as $show) {
            $result = $follower->refresh($show, $fallback, (bool) $one);
            $this->line(sprintf('%s: %s', $show->slug, is_int($result) ? "$result new" : "error $result"));
        }

        return self::SUCCESS;
    }
}
