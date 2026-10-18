<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A show. Hosted on the forum (audio uploaded here, the feed served from here)
 * or followed (feed_url set: episodes are read from someone else's feed).
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('earshot_shows')) {
            return;
        }

        $schema->create('earshot_shows', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug', 80)->unique();
            $table->string('title', 120);
            $table->string('tagline', 200)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            // Where its episode discussions start (flarum/tags), if anywhere.
            $table->unsignedInteger('tag_id')->nullable();

            $table->string('cover_path', 100)->nullable();
            // A followed show's artwork stays on its own host.
            $table->string('cover_url', 1000)->nullable();
            $table->string('color', 7)->nullable();
            $table->string('language', 12)->default('en');
            $table->string('category', 80)->nullable();
            $table->boolean('explicit')->default(false);
            // Subscribe links: apple, spotify, pocketcasts, overcast, youtube.
            $table->json('links')->nullable();

            // Follow mode.
            $table->string('feed_url', 1000)->nullable();
            $table->string('feed_etag', 200)->nullable();
            $table->string('feed_modified', 100)->nullable();
            $table->dateTime('fetched_at')->nullable();
            $table->string('fetch_error', 60)->nullable();

            $table->unsignedInteger('episode_count')->default(0);
            $table->unsignedInteger('follower_count')->default(0);
            $table->dateTime('last_episode_at')->nullable();
            $table->timestamps();

            $table->index('last_episode_at');
            $table->index('fetched_at');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    },
    'down' => fn (Builder $schema) => $schema->dropIfExists('earshot_shows'),
];
