<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $today = Carbon::today()->toDateString();

        $query = Product::with('category')
            ->withSum(['batches as tong_ton_kha_dung' => function ($q) use ($today) {
                $q->where('status', 'available')
                  ->where('available_quantity', '>', 0)
                  ->whereDate('expires_at', '>', $today);
            }], 'available_quantity');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        return response()->json([
            'status' => 'success',
            'data'   => $query->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id'    => 'required|exists:categories,id',
            'name'           => 'required|string|max:150',
            'slug'           => 'nullable|string|max:180|unique:products,slug',
            'description'    => 'nullable|string',
            'image_path'     => 'nullable|string|max:255',
            'base_price'     => 'required|numeric|min:0',
            'unit'           => 'required|string|max:20',
            'is_active'      => 'boolean',
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']).'-'.Str::lower(Str::random(5));
        $validated['is_active'] = $validated['is_active'] ?? true;

        $product = Product::create($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Thêm sản phẩm mới thành công.',
            'data'    => $product->load('category'),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $today = Carbon::today()->toDateString();

        $product = Product::with(['category', 'batches' => function ($q) use ($today) {
            $q->where('status', 'available')
              ->where('available_quantity', '>', 0)
              ->whereDate('expires_at', '>', $today)
              ->orderBy('expires_at', 'asc');
        }])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data'   => $product,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $validated = $request->validate([
            'category_id' => 'sometimes|exists:categories,id',
            'name' => 'sometimes|string|max:150',
            'slug' => 'sometimes|string|max:180|unique:products,slug,'.$id,
            'description' => 'nullable|string',
            'image_path' => 'nullable|string|max:255',
            'base_price' => 'sometimes|numeric|min:0',
            'unit' => 'sometimes|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        $product->update($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Cập nhật toàn bộ thông tin sản phẩm thành công.',
            'data'    => $product,
        ]);
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $product->update(['is_active' => $validated['is_active']]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Cập nhật trạng thái kinh doanh sản phẩm thành công.',
            'data'    => $product,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Đã xóa sản phẩm.',
        ]);
    }
}