<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * One row per episode. The conversation about it is a real discussion, so
 * replies, mentions, subscriptions, search and who may see it are core's.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('earshot_episodes')) {
            return;
        }

        $schema->create('earshot_episodes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('show_id');
            $table->unsignedInteger('discussion_id')->nullable();
            // The feed's <guid>: how a followed feed's item is known again.
            $table->string('guid', 255);
            $table->string('title', 200);
            $table->unsignedSmallInteger('season')->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->text('summary')->nullable();

            $table->string('audio_path', 100)->nullable();
            $table->string('audio_url', 1000)->nullable();
            $table->string('audio_type', 60)->nullable();
            $table->unsignedBigInteger('audio_bytes')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('image_url', 1000)->nullable();
            // [[seconds, title], ...], sorted.
            $table->json('chapters')->nullable();
            $table->boolean('explicit')->default(false);
            $table->dateTime('published_at');
            $table->timestamps();

            $table->unique(['show_id', 'guid']);
            $table->index(['show_id', 'published_at']);
            $table->unique('discussion_id');
            $table->foreign('show_id')->references('id')->on('earshot_shows')->cascadeOnDelete();
            $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
        });
    },
    'down' => fn (Builder $schema) => $schema->dropIfExists('earshot_episodes'),
];
