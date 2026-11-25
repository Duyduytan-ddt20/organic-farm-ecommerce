<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Batch;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class FefoAllocationService
{
    /** Validate stock at checkout; confirmation repeats this check while holding row locks. */
    public function assertAvailable(array $items): void
    {
        foreach ($this->requestedByProduct($items) as $productId => $requestedCenti) {
            $product = Product::query()->whereKey($productId)->where('is_active', true)->firstOrFail();
            $availableCenti = $this->eligibleBatches((int) $productId)->get()
                ->sum(fn (Batch $batch) => $this->toCenti($batch->available_quantity));

            if ($availableCenti < $requestedCenti) {
                throw new InsufficientStockException($requestedCenti / 100, $availableCenti / 100, $product->name);
            }
        }
    }

    public function confirm(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $lockedOrder = Order::query()->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status === 'confirmed') {
                return $lockedOrder->load('items.product', 'items.allocations.batch');
            }
            if ($lockedOrder->status !== 'pending') {
                abort(409, 'Chỉ đơn hàng đang chờ mới được xác nhận.');
            }

            foreach ($lockedOrder->items as $item) {
                $product = Product::query()->whereKey($item->product_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
                $requestedCenti = $this->toCenti($item->quantity);
                $batches = $this->eligibleBatches($product->id)->lockForUpdate()->get();
                $availableCenti = $batches->sum(fn (Batch $batch) => $this->toCenti($batch->available_quantity));

                if ($availableCenti < $requestedCenti) {
                    throw new InsufficientStockException($requestedCenti / 100, $availableCenti / 100, $product->name);
                }

                $remainingCenti = $requestedCenti;
                foreach ($batches as $batch) {
                    if ($remainingCenti === 0) {
                        break;
                    }
                    $batchCenti = $this->toCenti($batch->available_quantity);
                    $allocatedCenti = min($remainingCenti, $batchCenti);
                    $allocatedQuantity = $allocatedCenti / 100;

                    $batch->available_quantity = number_format(($batchCenti - $allocatedCenti) / 100, 2, '.', '');
                    $batch->save();
                    $item->allocations()->create([
                        'batch_id' => $batch->id,
                        'quantity' => number_format($allocatedQuantity, 2, '.', ''),
                        'allocated_at' => now(),
                    ]);
                    $remainingCenti -= $allocatedCenti;
                }
            }

            $lockedOrder->update(['status' => 'confirmed', 'confirmed_at' => now()]);

            return $lockedOrder->fresh('items.product', 'items.allocations.batch');
        }, 3);
    }

    private function eligibleBatches(int $productId)
    {
        return Batch::query()->where('product_id', $productId)
            ->where('status', 'available')
            ->where('available_quantity', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')->orderBy('id');
    }

    private function requestedByProduct(array $items): array
    {
        $requested = [];
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $requested[$productId] = ($requested[$productId] ?? 0) + $this->toCenti($item['quantity']);
        }

        return $requested;
    }

    private function toCenti(int|float|string $quantity): int
    {
        return (int) round((float) $quantity * 100);
    }
}
