<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/* Members who follow a show hear about each new episode. */
return Migration::createTableIfNotExists('earshot_follows', function (Blueprint $table) {
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('show_id');
    $table->dateTime('created_at');

    $table->primary(['user_id', 'show_id']);
    $table->index('show_id');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('show_id')->references('id')->on('earshot_shows')->cascadeOnDelete();
});
