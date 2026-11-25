<?php

use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public routes (Không cần đăng nhập)
    Route::get('/health', fn () => response()->json(['status' => 'ok']));
    Route::post('/orders', [OrderController::class, 'store']);
    // Route::get('/products/{slug}', [ProductController::class, 'show']);
    // Route::get('/trace/{qr_token}', [TraceController::class, 'show']);

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        // Nhóm Farm Staff
        Route::middleware('role:Staff,Admin')->prefix('staff')->group(function () {
            // Route::get('/batches', [BatchController::class, 'index']);
            // Route::post('/stock-disposals', [StockDisposalController::class, 'store']);
        });

        // Nhóm Sales
        Route::middleware('role:Sales,Admin')->prefix('sales')->group(function () {
            Route::get('/orders', [OrderController::class, 'index']);
            Route::get('/orders/{order}', [OrderController::class, 'show']);
            Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
        });
    });
});
