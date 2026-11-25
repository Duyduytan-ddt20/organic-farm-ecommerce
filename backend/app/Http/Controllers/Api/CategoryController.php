<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => Category::withCount('products')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ten_danh_muc' => 'required|string|max:100|unique:categories,ten_danh_muc',
        ]);

        $category = Category::create([
            'ten_danh_muc' => $validated['ten_danh_muc'],
            'slug'         => Str::slug($validated['ten_danh_muc']),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Tạo danh mục thành công.',
            'data'    => $category,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $category = Category::with('products')->findOrFail($id);
        return response()->json(['status' => 'success', 'data' => $category]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $validated = $request->validate([
            'ten_danh_muc' => "required|string|max:100|unique:categories,ten_danh_muc,{$id}",
        ]);

        $category->update([
            'ten_danh_muc' => $validated['ten_danh_muc'],
            'slug'         => Str::slug($validated['ten_danh_muc']),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Cập nhật danh mục thành công.',
            'data'    => $category,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $category->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Đã xóa danh mục.',
        ]);
    }
}