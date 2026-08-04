<?php

use Illuminate\Support\Facades\Route;
use Modules\Club\Http\Controllers\ClubController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('clubs', ClubController::class)->names('club');
});
