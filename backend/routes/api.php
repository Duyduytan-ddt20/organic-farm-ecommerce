<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public routes (Không cần đăng nhập)
    Route::get('/health', fn () => response()->json(['status' => 'ok']));
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
            // Route::post('/orders/{id}/confirm', [OrderController::class, 'confirm']);
        });
    });
    Route::apiResource('categories', CategoryController::class);

    Route::apiResource('products', ProductController::class);
    Route::patch('products/{id}/status', [ProductController::class, 'toggleStatus']);

    Route::get('batches/expiring-soon', [BatchController::class, 'expiringSoon']);
    Route::apiResource('batches', BatchController::class);
    Route::patch('batches/{id}/discount', [BatchController::class, 'applyDiscount']);
    Route::prefix('v1')->group(function () {
    // ... các route cũ giữ nguyên
    Route::get('trace/{ma_lo}', [BatchController::class, 'traceByCode']);
});
});