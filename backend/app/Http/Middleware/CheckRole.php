<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        $roleName = $user?->role?->role_name;
        if (! $user || ! $user->is_active || ! in_array($roleName, $roles, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bạn không có quyền thực hiện thao tác này.',
            ], 403);
        }

        return $next($request);
    }
}
