<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFarmingLogRequest;
use App\Http\Resources\FarmingLogResource;
use App\Models\Batch;
use Illuminate\Http\JsonResponse;

class FarmingLogController extends Controller
{
    /**
     * Ghi nhật ký canh tác. Không đổi tồn kho. staff_id lấy từ người đăng nhập.
     */
    public function store(StoreFarmingLogRequest $request, Batch $batch): JsonResponse
    {
        $data = $request->validated();

        $log = $batch->farmingLogs()->create([
            'staff_id' => $request->user()->id,
            'activity' => $data['activity'],
            'description' => $data['description'] ?? null,
            'image_path' => null,
            'performed_at' => $data['performed_at'],
        ]);

        $log->setRelation('staff', $request->user());

        return response()->json([
            'message' => 'Đã ghi nhật ký canh tác.',
            'data' => new FarmingLogResource($log),
        ], 201);
    }
}
