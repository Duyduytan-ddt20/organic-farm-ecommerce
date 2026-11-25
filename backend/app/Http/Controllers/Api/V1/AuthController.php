<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Đăng ký tài khoản khách hàng và cấp token Sanctum.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $role = Role::query()->where('role_name', 'Customer')->firstOrFail();

        $user = User::query()->create([
            'role_id' => $role->id,
            'full_name' => $request->string('full_name')->toString(),
            'email' => $request->string('email')->toString(),
            'phone' => $request->input('phone'),
            'password' => $request->string('password')->toString(),
            'is_active' => true,
        ]);

        $user->load('role');

        return $this->tokenResponse($user, 'Đăng ký thành công.', 201);
    }

    /**
     * Đăng nhập bằng email và mật khẩu, trả về Bearer token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if (!$user || !Hash::check($request->string('password')->toString(), $user->password)) {
            return response()->json([
                'message' => 'Email hoặc mật khẩu không đúng.',
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Tài khoản đã bị khóa.',
            ], 403);
        }

        $user->load('role');

        return $this->tokenResponse($user, 'Đăng nhập thành công.', 200);
    }

    /**
     * Thông tin tài khoản đang đăng nhập.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role');

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Thu hồi token hiện tại.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        Auth::forgetGuards();

        return response()->json([
            'message' => 'Đăng xuất thành công.',
        ]);
    }

    private function tokenResponse(User $user, string $message, int $status): JsonResponse
    {
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => $message,
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => new UserResource($user),
            ],
        ], $status);
    }
}
