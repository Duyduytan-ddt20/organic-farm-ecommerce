<?php

use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FarmingLogController;
use App\Http\Controllers\Api\V1\QrCodeController;
use App\Http\Controllers\Api\V1\RoleAccessController;
use App\Http\Controllers\Api\V1\StockDisposalController;
use App\Http\Controllers\Api\V1\TraceController;
use App\Http\Controllers\Api\V1\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
    ]));

    Route::get('/trace/{qr_token}', [TraceController::class, 'show']);

    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    });

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::middleware('role:Customer,Admin')->prefix('customer')->group(function () {
            Route::get('/dashboard', [RoleAccessController::class, 'customer']);
            Route::get('/orders', [OrderController::class, 'customerIndex']);
            Route::post('/orders', [OrderController::class, 'store']);
        });

        Route::middleware('role:Staff,Admin')->prefix('staff')->group(function () {
            Route::get('/dashboard', [RoleAccessController::class, 'staff']);
        });

        Route::middleware('role:Sales,Admin')->prefix('sales')->group(function () {
            Route::get('/dashboard', [RoleAccessController::class, 'sales']);
            Route::get('/orders', [OrderController::class, 'salesIndex']);
            Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
        });

        Route::middleware('role:Admin')->prefix('admin')->group(function () {
            Route::get('/dashboard', [RoleAccessController::class, 'admin']);
        });

        Route::middleware('role:Staff,Admin')->group(function () {
            Route::get('/batches/{batch}/qr', [QrCodeController::class, 'show']);
            Route::get('/batches/{batch}/farming-logs', [FarmingLogController::class, 'index']);
            Route::post('/batches/{batch}/farming-logs', [FarmingLogController::class, 'store']);
            Route::get('/batches/{batch}/disposals', [StockDisposalController::class, 'index']);
            Route::post('/batches/{batch}/disposals', [StockDisposalController::class, 'store']);
        });
    });
    Route::apiResource('categories', CategoryController::class);

    Route::apiResource('products', ProductController::class);
    Route::patch('products/{id}/status', [ProductController::class, 'toggleStatus']);

    Route::get('batches/expiring-soon', [BatchController::class, 'expiringSoon']);
    Route::apiResource('batches', BatchController::class);
    Route::patch('batches/{id}/discount', [BatchController::class, 'applyDiscount']);
<<<<<<< HEAD
    Route::prefix('v1')->group(function () {
    // ... các route cũ giữ nguyên
    Route::get('trace/{ma_lo}', [BatchController::class, 'traceByCode']);
});
});
=======
});
>>>>>>> a9b0d69c48b5a5888882ecc16099ac8b29bfd777
