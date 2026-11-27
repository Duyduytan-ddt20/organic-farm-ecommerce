<?php

use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\OrderController as ClaimOrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FarmingLogController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\QrCodeController;
use App\Http\Controllers\Api\V1\RoleAccessController;
use App\Http\Controllers\Api\V1\StockDisposalController;
use App\Http\Controllers\Api\V1\TraceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));
    Route::get('/trace/{qr_token}', [TraceController::class, 'show']);

    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    });

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::middleware('role:Customer,Admin')->group(function () {
            Route::post('/orders', [ClaimOrderController::class, 'store']);
            if (app()->environment(['local', 'testing'])) {
                Route::get('/orders/preview', [ClaimOrderController::class, 'showDemo']);
            }
            Route::get('/orders/{order}', [ClaimOrderController::class, 'showOwn'])
                ->whereNumber('order');
            Route::post('/orders/{order}/claims', [ClaimController::class, 'store'])
                ->whereNumber('order');
            Route::put('/claims/{claim}', [ClaimController::class, 'update'])
                ->whereNumber('claim');
            Route::delete('/claims/{claim}/attachments/{attachment}', [ClaimController::class, 'deleteAttachment'])
                ->whereNumber('claim')
                ->whereNumber('attachment');

            Route::get('/customer/dashboard', [RoleAccessController::class, 'customer']);
            Route::get('/customer/orders', [OrderController::class, 'customerIndex']);
            Route::post('/customer/orders', [OrderController::class, 'store']);
        });

        Route::middleware('role:Staff,Admin')->group(function () {
            Route::get('/staff/dashboard', [RoleAccessController::class, 'staff']);
            Route::get('/batches/{batch}/qr', [QrCodeController::class, 'show']);
            Route::get('/batches/{batch}/farming-logs', [FarmingLogController::class, 'index']);
            Route::post('/batches/{batch}/farming-logs', [FarmingLogController::class, 'store']);
            Route::get('/batches/{batch}/disposals', [StockDisposalController::class, 'index']);
            Route::post('/batches/{batch}/disposals', [StockDisposalController::class, 'store']);
        });

        Route::middleware('role:Sales,Admin')->group(function () {
            Route::get('/sales/dashboard', [RoleAccessController::class, 'sales']);
            Route::get('/sales/orders', [OrderController::class, 'salesIndex']);
            Route::post('/sales/orders/{order}/confirm', [OrderController::class, 'confirm']);
        });

        Route::middleware('role:Admin')->get('/admin/dashboard', [RoleAccessController::class, 'admin']);
    });

    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('products', ProductController::class);
    Route::patch('products/{id}/status', [ProductController::class, 'toggleStatus']);
    Route::get('batches/expiring-soon', [BatchController::class, 'expiringSoon']);
    Route::apiResource('batches', BatchController::class);
    Route::patch('batches/{id}/discount', [BatchController::class, 'applyDiscount']);
});
