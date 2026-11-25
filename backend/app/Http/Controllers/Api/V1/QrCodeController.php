<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Services\QrGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class QrCodeController extends Controller
{
    /**
     * Trả SVG của QR. Token lấy từ lô, không tự tạo.
     */
    public function show(Batch $batch, QrGeneratorService $qrGenerator): Response|JsonResponse
    {
        if (!is_string($batch->qr_token) || $batch->qr_token === '') {
            return response()->json([
                'message' => 'Lô chưa có mã truy xuất.',
            ], 422);
        }

        return response($qrGenerator->svgForToken($batch->qr_token), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }
}
