<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Batch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FefoAllocationService
{
    public function assertAvailable(array $items): void
    {
        $requestedByProduct = [];
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $requestedByProduct[$productId] = ($requestedByProduct[$productId] ?? 0)
                + $this->toCents($item['quantity']);
        }

        foreach ($requestedByProduct as $productId => $requestedCents) {
            $product = Product::query()->whereKey($productId)->where('is_active', true)->firstOrFail();
            $availableCents = $this->eligibleBatches($productId)
                ->get()
                ->sum(fn (Batch $batch) => $this->toCents($batch->available_quantity));

            if ($availableCents < $requestedCents) {
                throw new InsufficientStockException(
                    $requestedCents / 100,
                    $availableCents / 100,
                    $product->name,
                );
            }
        }
    }

    public function ensureAvailable(int $productId, mixed $quantity): void
    {
        $requestedCents = $this->toCents($quantity);
        $availableCents = $this->eligibleBatches($productId)
            ->get()
            ->sum(fn (Batch $batch) => $this->toCents($batch->available_quantity));

        if ($availableCents < $requestedCents) {
            throw ValidationException::withMessages([
                'items' => 'Số lượng tồn khả dụng không đủ cho sản phẩm đã chọn.',
            ]);
        }
    }

    public function confirm(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $lockedOrder = Order::query()
                ->with('items')
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status === 'confirmed') {
                return $lockedOrder->load('items.product', 'items.allocations.batch');
            }

            if ($lockedOrder->status !== 'pending') {
                abort(409, 'Chỉ đơn hàng đang chờ mới được xác nhận.');
            }

            $this->assertAvailable(
                $lockedOrder->items->map(fn (OrderItem $item) => [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                ])->all()
            );

            foreach ($lockedOrder->items as $item) {
                $this->allocate($item);
            }

            $lockedOrder->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            return $lockedOrder->fresh('items.product', 'items.allocations.batch');
        }, 3);
    }

    public function allocate(OrderItem $item): array
    {
        $remainingCents = $this->toCents($item->quantity);
        $allocations = [];
        $batches = $this->eligibleBatches((int) $item->product_id)
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

    private function eligibleBatches(int $productId)
    {
        return Batch::query()
            ->where('product_id', $productId)
            ->where('status', 'available')
            ->where('available_quantity', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->orderBy('id');
    }

    private function toCents(mixed $value): int
    {
        $normalized = trim((string) $value);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
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
