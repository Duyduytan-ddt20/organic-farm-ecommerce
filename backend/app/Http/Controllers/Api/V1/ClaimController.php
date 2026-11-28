<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ClaimController extends Controller
{
    public function store(Request $request, Order $order): JsonResponse
    {
        if ((int) $order->user_id !== (int) $request->user()->getAuthIdentifier()) {
            abort(404);
        }

        if ($order->status !== 'delivered') {
            throw ValidationException::withMessages([
                'order' => 'Chỉ có thể gửi khiếu nại cho đơn hàng đã giao.',
            ]);
        }

        $data = $request->validate([
            'order_item_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'claimed_quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'evidence' => [
                'required',
                'file',
                'image',
                'mimes:jpg,png',
                'max:5120',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof \Illuminate\Http\UploadedFile) {
                        return;
                    }

                    if (! in_array(strtolower($value->getClientOriginalExtension()), ['jpg', 'png'], true)) {
                        $fail('Ảnh minh chứng chỉ được có định dạng .jpg hoặc .png.');

                        return;
                    }

                    if ($value->getSize() >= 5 * 1024 * 1024) {
                        $fail('Dung lượng ảnh minh chứng phải nhỏ hơn 5 MB.');
                    }
                },
            ],
        ]);

        $orderItem = $order->items()->whereKey($data['order_item_id'])->first();
        if (! $orderItem) {
            throw ValidationException::withMessages([
                'order_item_id' => 'Sản phẩm không thuộc đơn hàng này.',
            ]);
        }

        if ((float) $data['claimed_quantity'] > (float) $orderItem->quantity) {
            throw ValidationException::withMessages([
                'claimed_quantity' => 'Số lượng khiếu nại không được vượt quá số lượng đã mua.',
            ]);
        }

        $evidence = $request->file('evidence');
        $filePath = $evidence->store('claim-evidence', 'public');
        if (! $filePath) {
            throw new RuntimeException('Không thể lưu ảnh minh chứng khiếu nại.');
        }

        try {
            $claim = DB::transaction(function () use ($data, $evidence, $filePath): Claim {
                $claim = Claim::query()->create([
                    'order_item_id' => $data['order_item_id'],
                    'reason' => $data['reason'],
                    'description' => $data['description'] ?? null,
                    'claimed_quantity' => $data['claimed_quantity'],
                    'status' => 'pending',
                    'submitted_at' => now(),
                ]);

                $claim->attachments()->create([
                    'file_path' => $filePath,
                    'original_name' => Str::limit($evidence->getClientOriginalName(), 255, ''),
                    'mime_type' => $evidence->getMimeType(),
                    'file_size_bytes' => $evidence->getSize(),
                    'created_at' => now(),
                ]);

                return $claim;
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($filePath);

            throw $exception;
        }

        return response()->json([
            'message' => 'Đã gửi yêu cầu khiếu nại. Nhân viên sẽ sớm kiểm tra và phản hồi.',
            'data' => [
                'id' => $claim->id,
                'order_item_id' => $claim->order_item_id,
                'status' => $claim->status,
                'submitted_at' => $claim->submitted_at?->format('Y-m-d H:i:s'),
            ],
        ], 201);
    }
}
