<?php

use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public routes (Không cần đăng nhập)
    Route::get('/health', fn () => response()->json(['status' => 'ok']));
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/auth/logout', [AuthController::class, 'logout']);
    Route::middleware(['auth:sanctum', 'role:Customer,Admin'])->group(function () {
        Route::post('/orders', [OrderController::class, 'store']);
        if (app()->environment(['local', 'testing'])) {
            Route::get('/orders/preview', [OrderController::class, 'showDemo']);
        }
        Route::get('/orders/{order}', [OrderController::class, 'showOwn']);
        Route::post('/orders/{order}/claims', [ClaimController::class, 'store']);
        Route::put('/claims/{claim}', [ClaimController::class, 'update']);
        Route::delete('/claims/{claim}/attachments/{attachment}', [ClaimController::class, 'deleteAttachment']);
    });
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
