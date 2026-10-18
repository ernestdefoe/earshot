<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/* Where a member stopped in each episode, so any device resumes there. Guests keep theirs in the browser. */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('earshot_progress')) {
            return;
        }

        $schema->create('earshot_progress', function (Blueprint $table) {
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
    },
    'down' => fn (Builder $schema) => $schema->dropIfExists('earshot_progress'),
];
