<?php

use Illuminate\Support\Facades\Route;
use Modules\Anipo\Http\Controllers\AnipoController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('anipos', AnipoController::class)->names('anipo');
});
