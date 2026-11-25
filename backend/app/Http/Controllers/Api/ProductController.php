<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $today = Carbon::today()->toDateString();

        $query = Product::with('category')
            ->withSum(['batches as tong_ton_kha_dung' => function ($q) use ($today) {
                $q->where('trang_thai', 'active')
                  ->where('so_luong_ton', '>', 0)
                  ->whereDate('ngay_het_han', '>', $today);
            }], 'so_luong_ton');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('search')) {
            $query->where('ten_san_pham', 'like', '%' . $request->search . '%');
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
            'ten_san_pham'   => 'required|string|max:150',
            'hinh_anh'       => 'nullable|string|max:255',
            'gia_ban_co_ban' => 'required|numeric|min:0',
            'don_vi_tinh'    => 'required|string|max:20',
            'trang_thai'     => 'boolean',
        ]);

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
            $q->where('trang_thai', 'active')
              ->where('so_luong_ton', '>', 0)
              ->whereDate('ngay_het_han', '>', $today)
              ->orderBy('ngay_het_han', 'asc');
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
            'category_id'    => 'required|exists:categories,id',
            'ten_san_pham'   => 'required|string|max:150',
            'hinh_anh'       => 'nullable|string|max:255',
            'gia_ban_co_ban' => 'required|numeric|min:0',
            'don_vi_tinh'    => 'required|string|max:20',
            'trang_thai'     => 'required|boolean',
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
            'trang_thai' => 'required|boolean',
        ]);

        $product->update(['trang_thai' => $validated['trang_thai']]);

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