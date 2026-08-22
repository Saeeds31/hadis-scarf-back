<?php

use Illuminate\Support\Facades\Route;
use Modules\CardTransfer\Http\Controllers\CardTransferController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::put('card-transfer/receipt/{receiptId}/review', [CardTransferController::class, 'reviewReceipt']);
});
Route::middleware(['auth:sanctum'])->prefix('v1/front/card-transfer')->group(function () {
    Route::post('order', [CardTransferController::class, 'store']);
    Route::post('order/{orderId}/upload-receipt', [CardTransferController::class, 'uploadReceipt']);
    Route::get('order/{orderId}/status', [CardTransferController::class, 'status']);
});
