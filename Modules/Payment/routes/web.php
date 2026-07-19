<?php

use Illuminate\Support\Facades\Route;
use Modules\Payment\Http\Controllers\PaymentController;
use Modules\Payment\Http\Controllers\CallbackController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('payments', PaymentController::class)->names('payment');
});

Route::prefix('payment')->group(function () {

    Route::get(
        '/callback/{gateway}',
        CallbackController::class
    )->name('payment.callback');
});
