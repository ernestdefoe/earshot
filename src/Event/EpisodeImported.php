<?php

namespace ErnestDefoe\Earshot\Event;

use ErnestDefoe\Earshot\Model\Episode;

/**
 * A followed feed's item became an episode. $item is FeedReader's reading of
 * it, including what the core does not use (the podcast:transcript address).
 */
class EpisodeImported
{
    /** @param array<string, mixed> $item */
    public function __construct(public Episode $episode, public array $item)
    {
    }
}
