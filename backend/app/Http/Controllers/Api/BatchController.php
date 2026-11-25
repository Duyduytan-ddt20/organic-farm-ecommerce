<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Batch::with('product:id,ten_san_pham,don_vi_tinh,gia_ban_co_ban')
            ->orderBy('ngay_het_han', 'asc');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('trang_thai')) {
            $query->where('trang_thai', $request->trang_thai);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $query->get(),
        ]);
    }

    public function expiringSoon(Request $request): JsonResponse
    {
        $days = (int) $request->input('days', 2);
        $today = Carbon::today();
        $thresholdDate = $today->copy()->addDays($days);

        $batches = Batch::with('product:id,ten_san_pham,don_vi_tinh,gia_ban_co_ban')
            ->where('trang_thai', 'active')
            ->where('so_luong_ton', '>', 0)
            ->whereDate('ngay_het_han', '>=', $today->toDateString())
            ->whereDate('ngay_het_han', '<=', $thresholdDate->toDateString())
            ->orderBy('ngay_het_han', 'asc')
            ->get()
            ->map(function ($batch) use ($today) {
                $daysLeft = $today->diffInDays(Carbon::parse($batch->ngay_het_han), false);
                $batch->so_ngay_con_lai = $daysLeft;
                $batch->muc_canh_bao = $daysLeft <= 1 ? 'URGENT_RED' : 'WARNING_ORANGE';
                return $batch;
            });

        return response()->json([
            'status'  => 'success',
            'message' => "Danh sách lô hàng sắp hết hạn trong vòng {$days} ngày tới.",
            'total'   => $batches->count(),
            'data'    => $batches,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id'     => 'required|exists:products,id',
            'ma_lo'          => 'nullable|string|max:50|unique:batches,ma_lo',
            'ngay_thu_hoach' => 'required|date',
            'ngay_het_han'   => 'required|date|after:ngay_thu_hoach',
            'so_luong_nhap'  => 'required|numeric|min:0.1',
            'phan_tram_giam' => 'nullable|integer|min:0|max:90',
        ]);

        $maLo = $validated['ma_lo'] ?? ('LO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4)));

        $batch = Batch::create([
            'product_id'       => $validated['product_id'],
            'ma_lo'            => $maLo,
            'ngay_thu_hoach'   => $validated['ngay_thu_hoach'],
            'ngay_het_han'     => $validated['ngay_het_han'],
            'so_luong_nhap'    => $validated['so_luong_nhap'],
            'so_luong_ton'     => $validated['so_luong_nhap'],
            'so_luong_hao_hut' => 0,
            'phan_tram_giam'   => $validated['phan_tram_giam'] ?? 0,
            'trang_thai'       => 'active',
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Nhập lô hàng mới thành công.',
            'data'    => $batch->load('product'),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $batch = Batch::with('product')->findOrFail($id);
        return response()->json(['status' => 'success', 'data' => $batch]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $batch = Batch::findOrFail($id);
        $validated = $request->validate([
            'product_id'     => 'required|exists:products,id',
            'ngay_thu_hoach' => 'required|date',
            'ngay_het_han'   => 'required|date|after:ngay_thu_hoach',
            'so_luong_ton'   => 'required|numeric|min:0',
            'phan_tram_giam' => 'required|integer|min:0|max:90',
            'trang_thai'     => 'required|in:growing,active,expired,depleted',
        ]);

        $batch->update($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Cập nhật lô hàng thành công.',
            'data'    => $batch,
        ]);
    }

    public function applyDiscount(Request $request, int $id): JsonResponse
    {
        $batch = Batch::findOrFail($id);
        $validated = $request->validate([
            'phan_tram_giam' => 'required|integer|min:0|max:90',
        ]);

        $batch->update(['phan_tram_giam' => $validated['phan_tram_giam']]);

        return response()->json([
            'status'  => 'success',
            'message' => "Đã áp dụng mức giảm {$validated['phan_tram_giam']}% cho lô {$batch->ma_lo}.",
            'data'    => $batch,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $batch = Batch::findOrFail($id);
        $batch->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Đã xóa lô hàng.',
        ]);
    }
}