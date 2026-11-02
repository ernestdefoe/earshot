<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/* Where a member stopped in each episode, so any device resumes there. Guests keep theirs in the browser. */
return Migration::createTableIfNotExists('earshot_progress', function (Blueprint $table) {
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('episode_id');
    $table->unsignedInteger('position')->default(0);
    $table->boolean('finished')->default(false);
    $table->dateTime('updated_at');

    $table->primary(['user_id', 'episode_id']);
    $table->index('episode_id');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('episode_id')->references('id')->on('earshot_episodes')->cascadeOnDelete();
});
