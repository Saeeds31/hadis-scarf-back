<?php

use Illuminate\Support\Facades\Route;
use Modules\Club\Http\Controllers\ClubController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('clubs', ClubController::class)->names('club');
});
