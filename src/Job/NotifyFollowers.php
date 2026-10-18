<?php

namespace ErnestDefoe\Earshot\Job;

use ErnestDefoe\Earshot\Model\Episode;
use ErnestDefoe\Earshot\Notification\NewEpisode;
use Flarum\Notification\NotificationSyncer;
use Flarum\Queue\AbstractJob;
use Flarum\User\Guest;
use Flarum\User\User;

/**
 * Tells a show's followers about a new episode: those who may still read its
 * discussion, never its publisher, in chunks so a big audience never loads at once.
 */
class NotifyFollowers extends AbstractJob
{
    public function __construct(private int $episodeId)
    {
    }

    public function handle(NotificationSyncer $notifications): void
    {
        $episode = Episode::query()->with(['show', 'discussion'])->find($this->episodeId);
        $discussion = $episode?->discussion;
        if (! $episode || ! $discussion || $discussion->hidden_at) {
            return;
        }

        $blueprint = new NewEpisode($episode, $discussion->user);
        // One check for everybody when guests can read it, the usual case;
        // otherwise a check per follower, since a tag may be members-only.
        $public = $discussion->newQuery()->whereVisibleTo(new Guest())->whereKey($discussion->id)->exists();

        User::query()
            ->whereIn('id', fn ($q) => $q->select('user_id')->from('earshot_follows')->where('show_id', $episode->show_id))
            ->where('id', '!=', (int) $discussion->user_id)
            ->chunkById(200, function ($users) use ($blueprint, $discussion, $notifications, $public) {
                $recipients = $public ? $users : $users->filter(fn (User $u) => $discussion->newQuery()->whereVisibleTo($u)->whereKey($discussion->id)->exists());
                $notifications->sync($blueprint, $recipients->all());
            });
    }
}
