<?php

use Illuminate\Support\Facades\Route;
use Modules\Story\Http\Controllers\StoryController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('stories', StoryController::class)->names('story');
});
