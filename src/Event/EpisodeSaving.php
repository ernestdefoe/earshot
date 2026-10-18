<?php

namespace ErnestDefoe\Earshot\Event;

use ErnestDefoe\Earshot\Model\Episode;
use Flarum\User\User;

/**
 * An episode is about to be saved from the episode form (published or
 * changed). Add-ons read their own fields from $data and set them on the
 * episode, or throw a ValidationException.
 */
class EpisodeSaving
{
    /** @param array<string, mixed> $data what the form sent */
    public function __construct(public Episode $episode, public User $actor, public array $data)
    {
    }
}
