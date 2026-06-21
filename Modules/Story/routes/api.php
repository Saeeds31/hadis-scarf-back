<?php

use Illuminate\Support\Facades\Route;
use Modules\Story\Http\Controllers\StoryController;


Route::prefix('v1/stories')->group(function () {
    Route::get('/published', [StoryController::class, 'published']);
    Route::post('/{story}/view', [StoryController::class, 'incrementView']);
    Route::get('/search', [StoryController::class, 'search']);
});

Route::middleware(['auth:sanctum'])->prefix('v1/admin/stories')->group(function () {
    Route::get('/', [StoryController::class, 'index']);
    Route::post('/', [StoryController::class, 'store']);
    Route::get('/{story}', [StoryController::class, 'show']);
    Route::put('/{story}', [StoryController::class, 'update']);
    Route::delete('/{story}', [StoryController::class, 'destroy']);
});
