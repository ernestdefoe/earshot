<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/* The members who host a show: they publish its episodes and wear its ring in the hero. */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('earshot_hosts')) {
            return;
        }

        $schema->create('earshot_hosts', function (Blueprint $table) {
            $table->unsignedInteger('show_id');
            $table->unsignedInteger('user_id');
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['show_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('show_id')->references('id')->on('earshot_shows')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },
    'down' => fn (Builder $schema) => $schema->dropIfExists('earshot_hosts'),
];
