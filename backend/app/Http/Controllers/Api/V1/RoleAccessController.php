<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleAccessController extends Controller
{
    /**
     * Khu vực dành cho khách hàng.
     */
    public function customer(Request $request): JsonResponse
    {
        return $this->allow($request, 'Customer');
    }

    /**
     * Khu vực dành cho nhân viên nông trại.
     */
    public function staff(Request $request): JsonResponse
    {
        return $this->allow($request, 'Staff');
    }

    /**
     * Khu vực dành cho nhân viên bán hàng.
     */
    public function sales(Request $request): JsonResponse
    {
        return $this->allow($request, 'Sales');
    }

    /**
     * Khu vực dành cho quản trị viên.
     */
    public function admin(Request $request): JsonResponse
    {
        return $this->allow($request, 'Admin');
    }

    private function allow(Request $request, string $area): JsonResponse
    {
        $user = $request->user()->load('role');

        return response()->json([
            'message' => "Bạn được phép truy cập khu vực {$area}.",
            'data' => new UserResource($user),
        ]);
    }
}
