<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\ClaimAttachment;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClaimController extends Controller
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function store(Request $request, Order $order)
    {
        $this->assertCanClaim($request, $order);

        $data = $request->validate([
            'order_item_id' => [
                'required',
                'integer',
                Rule::exists('order_items', 'id')->where(fn ($query) => $query->where('order_id', $order->id)),
            ],
            'reason' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'claimed_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'images' => ['required', 'array', 'min:1', 'max:5'],
            'images.*' => ['required', 'file', function (string $attribute, mixed $value, \Closure $fail): void {
                $this->assertImage($value, $fail);
            }],
        ]);

        $item = OrderItem::query()->where('order_id', $order->id)->findOrFail($data['order_item_id']);
        if ((float) $data['claimed_quantity'] > (float) $item->quantity) {
            throw ValidationException::withMessages([
                'claimed_quantity' => 'Số lượng khiếu nại không được lớn hơn số lượng đã mua.',
            ]);
        }

        $storedPaths = [];
        try {
            $claim = DB::transaction(function () use ($data, $request, &$storedPaths) {
                $claim = Claim::create([
                    'order_item_id' => $data['order_item_id'],
                    'reason' => $data['reason'],
                    'description' => $data['description'] ?? null,
                    'claimed_quantity' => $data['claimed_quantity'],
                    'status' => 'pending',
                    'submitted_at' => now(),
                ]);

                foreach ($request->file('images', []) as $file) {
                    $path = $file->store('claims/'.$claim->id, 'public');
                    $storedPaths[] = $path;
                    $claim->attachments()->create([
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'file_size_bytes' => $file->getSize(),
                    ]);
                }

                return $claim->load('attachments');
            });
        } catch (\Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk('public')->delete($storedPaths);
            }
            throw $exception;
        }

        return response()->json(['status' => 'success', 'data' => $claim], 201);
    }

    public function update(Request $request, Claim $claim)
    {
        $this->assertCanEditClaim($request, $claim);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'claimed_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'images' => ['sometimes', 'array', 'max:5'],
            'images.*' => ['required', 'file', function (string $attribute, mixed $value, \Closure $fail): void {
                $this->assertImage($value, $fail);
            }],
        ]);

        $claim->loadMissing('orderItem');
        if ((float) $data['claimed_quantity'] > (float) $claim->orderItem->quantity) {
            throw ValidationException::withMessages([
                'claimed_quantity' => 'Số lượng khiếu nại không được lớn hơn số lượng đã mua.',
            ]);
        }

        $newImages = $request->file('images', []);
        $storedPaths = [];
        try {
            $updatedClaim = DB::transaction(function () use ($claim, $data, $newImages, &$storedPaths) {
                $lockedClaim = Claim::query()->lockForUpdate()->findOrFail($claim->id);
                if ($lockedClaim->status !== 'pending') {
                    throw ValidationException::withMessages([
                        'claim' => 'Chỉ sửa được khiếu nại đang chờ xử lý.',
                    ]);
                }

                if ($lockedClaim->attachments()->count() + count($newImages) > 5) {
                    throw ValidationException::withMessages([
                        'images' => 'Mỗi khiếu nại chỉ được có tối đa 5 ảnh.',
                    ]);
                }

                $lockedClaim->update([
                    'reason' => $data['reason'],
                    'description' => $data['description'] ?? null,
                    'claimed_quantity' => $data['claimed_quantity'],
                ]);

                foreach ($newImages as $file) {
                    $path = $file->store('claims/'.$lockedClaim->id, 'public');
                    if (! is_string($path)) {
                        throw new \RuntimeException('Không lưu được ảnh minh chứng.');
                    }
                    $storedPaths[] = $path;
                    $lockedClaim->attachments()->create([
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'file_size_bytes' => $file->getSize(),
                    ]);
                }

                return $lockedClaim->load('attachments');
            });
        } catch (\Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk('public')->delete($storedPaths);
            }
            throw $exception;
        }

        return response()->json(['status' => 'success', 'data' => $updatedClaim]);
    }

    public function deleteAttachment(Request $request, Claim $claim, ClaimAttachment $attachment)
    {
        $claim->loadMissing('orderItem.order');
        $user = $request->user();
        $isAdmin = $user?->role?->role_name === 'Admin';

        if (! $user || (! $isAdmin && (int) $claim->orderItem->order->user_id !== (int) $user->id)) {
            abort(403, 'Bạn không có quyền xóa ảnh khiếu nại này.');
        }

        if ((int) $attachment->claim_id !== (int) $claim->id) {
            abort(404);
        }

        if ($claim->status !== 'pending') {
            throw ValidationException::withMessages([
                'claim' => 'Chỉ xóa được ảnh khiếu nại đang chờ xử lý.',
            ]);
        }

        $disk = Storage::disk('public');
        $path = $attachment->file_path;
        $fileExists = $disk->exists($path);
        $fileContents = $fileExists ? $disk->get($path) : null;

        if ($fileExists && ! $disk->delete($path)) {
            throw new \RuntimeException('Không xóa được ảnh minh chứng khỏi bộ nhớ.');
        }

        try {
            DB::transaction(function () use ($attachment): void {
                if (! $attachment->delete()) {
                    throw new \RuntimeException('Không xóa được thông tin ảnh minh chứng.');
                }
            });
        } catch (\Throwable $exception) {
            if ($fileExists && ! $disk->put($path, $fileContents)) {
                throw new \RuntimeException('Không thể khôi phục ảnh sau khi cập nhật hồ sơ thất bại.', 0, $exception);
            }

            throw $exception;
        }

        return response()->json(['status' => 'success', 'message' => 'Đã xóa ảnh minh chứng.']);
    }

    private function assertCanEditClaim(Request $request, Claim $claim): void
    {
        $claim->loadMissing('orderItem.order');
        $user = $request->user();
        $isAdmin = $user?->role?->role_name === 'Admin';

        if (! $user || (! $isAdmin && (int) $claim->orderItem->order->user_id !== (int) $user->id)) {
            abort(403, 'Bạn không có quyền sửa khiếu nại này.');
        }

        if ($claim->status !== 'pending') {
            throw ValidationException::withMessages([
                'claim' => 'Chỉ sửa được khiếu nại đang chờ xử lý.',
            ]);
        }
    }

    private function assertCanClaim(Request $request, Order $order): void
    {
        $user = $request->user();
        $isAdmin = $user?->role?->role_name === 'Admin';
        if (! $user || (! $isAdmin && (int) $order->user_id !== (int) $user->id)) {
            abort(403, 'Bạn không có quyền gửi khiếu nại cho đơn hàng này.');
        }

        if ($order->status !== 'delivered') {
            throw ValidationException::withMessages([
                'order' => 'Chỉ gửi khiếu nại cho đơn hàng đã giao.',
            ]);
        }
    }

    private function assertImage(mixed $value, \Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('Tệp ảnh không hợp lệ.');

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());
        $expectedMime = self::MIME_BY_EXTENSION[$extension] ?? null;
        if ($expectedMime === null || $value->getMimeType() !== $expectedMime) {
            $fail('Chỉ chấp nhận ảnh .jpg, .png, .webp.');
        }

        if ($value->getSize() <= 0 || $value->getSize() >= self::MAX_BYTES) {
            $fail('Dung lượng ảnh phải nhỏ hơn 5MB.');
        }
    }
}
