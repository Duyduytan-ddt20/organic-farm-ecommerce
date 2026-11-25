<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Str;

class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Batch::with('product:id,name,unit,base_price')
            ->orderBy('expires_at', 'asc');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('status') || $request->filled('trang_thai')) {
            $query->where('status', $request->input('status', $request->input('trang_thai')));
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

        $batches = Batch::with('product:id,name,unit,base_price')
            ->where('status', '!=', 'closed')
            ->where('available_quantity', '>', 0)
            ->whereDate('expires_at', '>=', $today->toDateString())
            ->whereDate('expires_at', '<=', $thresholdDate->toDateString())
            ->orderBy('expires_at', 'asc')
            ->get()
            ->map(function ($batch) use ($today) {
                $daysLeft = $today->diffInDays(Carbon::parse($batch->expires_at), false);
                $batch->days_until_expiry = $daysLeft;
                $batch->warning_level = $daysLeft <= 1 ? 'URGENT_RED' : 'WARNING_ORANGE';
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
            'batch_code'     => 'nullable|string|max:50|unique:batches,batch_code',
            'ma_lo'          => 'nullable|string|max:50',
            'ngay_thu_hoach' => 'nullable|date',
            'harvested_at'   => 'nullable|date',
            'ngay_het_han'   => 'nullable|date',
            'expires_at'     => 'nullable|date',
            'so_luong_nhap'  => 'nullable|numeric|min:0.1',
            'received_quantity' => 'nullable|numeric|min:0.1',
            'phan_tram_giam' => 'nullable|integer|min:0|max:90',
            'growing_location' => 'nullable|string|max:255',
        ]);

        $batchCode = $validated['batch_code'] ?? $validated['ma_lo'] ?? null;
        if ($batchCode && Batch::where('batch_code', $batchCode)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Mã lô đã tồn tại.',
                'errors' => ['batch_code' => ['Mã lô đã tồn tại.']],
            ], 422);
        }

        $batchCode = $batchCode ?? ('LO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4)));
        $receivedQuantity = $validated['received_quantity'] ?? $validated['so_luong_nhap'] ?? 0;
        $harvestedAt = $validated['harvested_at'] ?? $validated['ngay_thu_hoach'];
        $expiresAt = $validated['expires_at'] ?? $validated['ngay_het_han'];

        $batch = Batch::create([
            'product_id'       => $validated['product_id'],
            'batch_code'       => $batchCode,
            'growing_location' => $validated['growing_location'] ?? 'Chưa cập nhật',
            'harvested_at'     => $harvestedAt,
            'expires_at'       => $expiresAt,
            'received_quantity'=> $receivedQuantity,
            'available_quantity'=> $receivedQuantity,
            'status'           => 'growing',
            'qr_token'         => Str::random(32),
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
            'product_id' => 'sometimes|exists:products,id',
            'batch_code' => 'sometimes|string|max:50|unique:batches,batch_code,' . $batch->id,
            'growing_location' => 'sometimes|string|max:255',
            'planted_at' => 'nullable|date',
            'harvested_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'received_quantity' => 'sometimes|numeric|min:0',
            'available_quantity' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:growing,available,blocked,closed',
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
        return response()->json([
            'status' => 'success',
            'message' => "Lô {$batch->batch_code} không áp dụng giảm giá riêng.",
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