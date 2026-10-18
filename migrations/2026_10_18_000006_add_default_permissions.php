<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

/*
 * Group IDs, not names: a name silently grants nothing.
 *
 * Anybody may listen. Starting a show is the admins' until the forum decides
 * otherwise; a show's hosts publish its episodes.
 */
return Migration::addPermissions([
    'earshot.view' => Group::GUEST_ID,
    'earshot.follow' => Group::MEMBER_ID,
    'earshot.manage' => Group::MODERATOR_ID,
]);
