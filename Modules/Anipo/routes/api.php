<?php

use Illuminate\Support\Facades\Route;
use Modules\Anipo\Http\Controllers\AnipoController;

Route::prefix('v1/admin/anipo/orders')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::post('send', [AnipoController::class, 'send']);
        Route::post('{orderId}/refresh', [AnipoController::class, 'refresh']);
    });

    