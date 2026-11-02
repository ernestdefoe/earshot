<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/* The members who host a show: they publish its episodes and wear its ring in the hero. */
return Migration::createTableIfNotExists('earshot_hosts', function (Blueprint $table) {
    $table->unsignedInteger('show_id');
    $table->unsignedInteger('user_id');
    $table->unsignedSmallInteger('position')->default(0);

    $table->primary(['show_id', 'user_id']);
    $table->index('user_id');
    $table->foreign('show_id')->references('id')->on('earshot_shows')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
