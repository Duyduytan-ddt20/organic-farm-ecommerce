<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use Illuminate\Http\JsonResponse;

class TraceController extends Controller
{
    private const TRACE_NOTICE = 'QR cung cấp thông tin truy xuất theo lô, không tự chứng nhận chất lượng.';

    /**
     * Tra cứu nguồn gốc công khai theo qr_token. Không trả tồn, giá, token hay phiếu xuất hủy.
     */
    public function show(string $qr_token): JsonResponse
    {
        $batch = Batch::query()->where('qr_token', $qr_token)->first();

        if (!$batch) {
            return response()->json([
                'message' => 'Không tìm thấy thông tin truy xuất.',
            ], 404);
        }

        $batch->load([
            'product:id,name,unit',
            'farmingLogs' => function ($query) {
                $query->orderBy('performed_at')->orderBy('id')->with('staff:id,full_name');
            },
        ]);

        $responsibleLog = $batch->farmingLogs->last();

        return response()->json([
            'data' => [
                'trace_notice' => self::TRACE_NOTICE,
                'product' => [
                    'id' => $batch->product?->id,
                    'name' => $batch->product?->name,
                    'unit' => $batch->product?->unit,
                ],
                'batch' => [
                    'batch_code' => $batch->batch_code,
                    'harvested_at' => $batch->harvested_at?->format('Y-m-d'),
                    'expires_at' => $batch->expires_at?->format('Y-m-d H:i:s'),
                    'growing_location' => $batch->growing_location,
                ],
                'responsible_staff' => $responsibleLog?->staff ? [
                    'id' => $responsibleLog->staff->id,
                    'full_name' => $responsibleLog->staff->full_name,
                ] : null,
                'farming_logs' => $batch->farmingLogs->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'activity' => $log->activity,
                        'description' => $log->description,
                        'image_path' => $log->image_path,
                        'performed_at' => $log->performed_at?->format('Y-m-d H:i:s'),
                        'staff' => $log->staff ? [
                            'id' => $log->staff->id,
                            'full_name' => $log->staff->full_name,
                        ] : null,
                    ];
                })->values(),
            ],
        ]);
    }
}
