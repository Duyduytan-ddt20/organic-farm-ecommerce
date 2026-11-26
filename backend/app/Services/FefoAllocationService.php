<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\OrderItem;
use Illuminate\Validation\ValidationException;

class FefoAllocationService
{
    public function ensureAvailable(int $productId, mixed $quantity): void
    {
        $requestedCents = $this->toCents($quantity);
        $availableCents = (int) Batch::query()
            ->where('product_id', $productId)
            ->where('status', 'available')
            ->where('available_quantity', '>', 0)
            ->where('expires_at', '>', now())
            ->get()
            ->sum(fn (Batch $batch) => $this->toCents($batch->available_quantity));

        if ($availableCents < $requestedCents) {
            throw ValidationException::withMessages([
                'items' => 'Số lượng tồn khả dụng không đủ cho sản phẩm đã chọn.',
            ]);
        }
    }

    public function allocate(OrderItem $item): array
    {
        $requestedCents = $this->toCents($item->quantity);
        $remainingCents = $requestedCents;
        $allocations = [];

        $batches = Batch::query()
            ->where('product_id', $item->product_id)
            ->where('status', 'available')
            ->where('available_quantity', '>', 0)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remainingCents === 0) {
                break;
            }

            $availableCents = $this->toCents($batch->available_quantity);
            $allocatedCents = min($remainingCents, $availableCents);
            if ($allocatedCents <= 0) {
                continue;
            }

            $detail = $item->details()->create([
                'batch_id' => $batch->id,
                'quantity' => $this->fromCents($allocatedCents),
                'allocated_at' => now(),
                'released_at' => null,
            ]);

            $remainingCents -= $allocatedCents;
            $batch->available_quantity = $this->fromCents($availableCents - $allocatedCents);
            if ($availableCents === $allocatedCents) {
                $batch->status = 'closed';
            }
            $batch->save();

            $allocations[] = [
                'batch_id' => $batch->id,
                'batch_code' => $batch->batch_code,
                'quantity' => $detail->quantity,
            ];
        }

        if ($remainingCents > 0) {
            throw ValidationException::withMessages([
                'items' => 'Sản phẩm này không đủ tồn khả dụng theo FEFO để xác nhận đơn.',
            ]);
        }

        return $allocations;
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
