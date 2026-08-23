<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportsController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin/reports')->group(function () {
    Route::get('/product-inventory', [ReportsController::class, 'productInventoryReport']);
    Route::get('/product-detailed', [ReportsController::class, 'productDetailedReport']);
    Route::get('/user-purchases', [ReportsController::class, 'userPurchaseReport']);
    Route::get('/inventory-movement', [ReportsController::class, 'inventoryMovementReport']);
    Route::get('/dashboard', [ReportsController::class, 'dashboardReport']);
});
