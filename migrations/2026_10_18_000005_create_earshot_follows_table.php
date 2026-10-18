<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/* Members who follow a show hear about each new episode. */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('earshot_follows')) {
            return;
        }

        $schema->create('earshot_follows', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('show_id');
            $table->dateTime('created_at');

            $table->primary(['user_id', 'show_id']);
            $table->index('show_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('show_id')->references('id')->on('earshot_shows')->cascadeOnDelete();
        });
    },
    'down' => fn (Builder $schema) => $schema->dropIfExists('earshot_follows'),
];
