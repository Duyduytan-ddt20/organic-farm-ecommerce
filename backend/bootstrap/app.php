<?php

use App\Exceptions\InsufficientStockException;
use App\Http\Middleware\CheckRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Đăng ký alias phân quyền dùng cho routes/api.php
        $middleware->alias([
            'role' => CheckRole::class,
        ]);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }
            if ($e instanceof ValidationException) {
                return response()->json(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ', 'errors' => $e->errors()], 422);
            }
            if ($e instanceof InsufficientStockException) {
                return response()->json(['status' => 'error', 'message' => $e->getMessage(), 'requested' => $e->requested, 'available' => $e->available], 409);
            }
            if ($e instanceof AuthenticationException) {
                return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
            }
            if ($e instanceof ModelNotFoundException) {
                return response()->json(['status' => 'error', 'message' => 'Không tìm thấy dữ liệu'], 404);
            }
            if ($e instanceof HttpExceptionInterface) {
                return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Yêu cầu không hợp lệ'], $e->getStatusCode());
            }
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Đã xảy ra lỗi hệ thống'], 500);
        });
    })->create();
