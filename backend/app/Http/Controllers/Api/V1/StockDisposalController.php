<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStockDisposalRequest;
use App\Http\Resources\StockDisposalResource;
use App\Models\Batch;
use App\Models\StockDisposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;

class StockDisposalController extends Controller
{
    /** @var list<string> */
    private const DISPOSABLE_STATUSES = ['available', 'blocked', 'growing'];

    public function index(Batch $batch): JsonResponse
    {
        $disposals = $batch->stockDisposals()
            ->with('staff:id,full_name')
            ->orderByDesc('disposed_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => StockDisposalResource::collection($disposals),
        ]);
    }

    /**
     * Ghi phiếu xuất hủy và trừ tồn trong một transaction có khóa dòng lô.
     */
    public function store(StoreStockDisposalRequest $request, Batch $batch): JsonResponse
    {
        $data = $request->validated();

        $disposal = DB::transaction(function () use ($request, $batch, $data): StockDisposal {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (!in_array($locked->status, self::DISPOSABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => $locked->status === 'closed'
                        ? 'Không thể xuất hủy lô đã đóng.'
                        : 'Không thể xuất hủy lô ở trạng thái này.',
                ]);
            }

            $availableCents = $this->toCents($locked->available_quantity);
            $requestedCents = $this->toCents($data['quantity']);

            if ($requestedCents > $availableCents) {
                throw ValidationException::withMessages([
                    'quantity' => 'Số lượng xuất hủy không được vượt quá số lượng tồn.',
                ]);
            }

            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('stock-disposals', 'public');
            }

            $disposal = StockDisposal::query()->create([
                'batch_id' => $locked->id,
                'staff_id' => $request->user()->id,
                'quantity' => $this->fromCents($requestedCents),
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
                'image_path' => $imagePath,
                'disposed_at' => $data['disposed_at'] ?? now(),
            ]);

            $remainingCents = $availableCents - $requestedCents;
            $locked->available_quantity = $this->fromCents($remainingCents);
            if ($remainingCents === 0 && $locked->status === 'available') {
                $locked->status = 'closed';
            }
            $locked->save();

            $disposal->setRelation('batch', $locked);
            $disposal->setRelation('staff', $request->user());

            return $disposal;
        });

        return response()->json([
            'message' => 'Đã ghi nhận xuất hủy.',
            'data' => new StockDisposalResource($disposal),
        ], 201);
    }

    private function toCents(mixed $value): int
    {
        $normalized = trim((string) $value);

        if (!preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            $normalized = number_format((float) $value, 2, '.', '');
        }

        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);
        $cents = ((int) $whole * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);

        return ($negative ? '-' : '').intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
