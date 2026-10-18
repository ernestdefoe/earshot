<?php

namespace ErnestDefoe\Earshot\Event;

use ErnestDefoe\Earshot\Model\Episode;
use Flarum\User\User;

/** An episode was saved from the episode form; its discussion exists. $isNew when it was just published. */
class EpisodeSaved
{
    /** @param array<string, mixed> $data what the form sent */
    public function __construct(public Episode $episode, public User $actor, public array $data, public bool $isNew)
    {
    }
}
