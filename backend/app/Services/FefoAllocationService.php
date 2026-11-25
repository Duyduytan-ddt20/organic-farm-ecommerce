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
        foreach ($this->requestedByProduct($items) as $productId => $requestedMilli) {
            $product = Product::query()->whereKey($productId)->where('is_active', true)->firstOrFail();
            $availableMilli = $this->eligibleBatches((int) $productId)->get()
                ->sum(fn (Batch $batch) => $this->toMilli($batch->quantity_available));

            if ($availableMilli < $requestedMilli) {
                throw new InsufficientStockException($requestedMilli / 1000, $availableMilli / 1000, $product->name);
            }
        }
    }

    public function confirm(Order $order, int $salesUserId): Order
    {
        return DB::transaction(function () use ($order, $salesUserId) {
            $lockedOrder = Order::query()->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status === 'confirmed') {
                return $lockedOrder->load('items.product', 'items.allocations.batch');
            }
            if ($lockedOrder->status !== 'pending') {
                abort(409, 'Chỉ đơn hàng đang chờ mới được xác nhận.');
            }

            foreach ($lockedOrder->items as $item) {
                $product = Product::query()->whereKey($item->product_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
                $requestedMilli = $this->toMilli($item->quantity);
                $batches = $this->eligibleBatches($product->id)->lockForUpdate()->get();
                $availableMilli = $batches->sum(fn (Batch $batch) => $this->toMilli($batch->quantity_available));

                if ($availableMilli < $requestedMilli) {
                    throw new InsufficientStockException($requestedMilli / 1000, $availableMilli / 1000, $product->name);
                }

                $remainingMilli = $requestedMilli;
                foreach ($batches as $batch) {
                    if ($remainingMilli === 0) {
                        break;
                    }
                    $batchMilli = $this->toMilli($batch->quantity_available);
                    $allocatedMilli = min($remainingMilli, $batchMilli);
                    $allocatedQuantity = $allocatedMilli / 1000;
                    $lineTotal = round($allocatedQuantity * (float) $item->unit_price, 2);

                    $batch->quantity_available = number_format(($batchMilli - $allocatedMilli) / 1000, 3, '.', '');
                    $batch->save();
                    $item->allocations()->create([
                        'batch_id' => $batch->id,
                        'allocated_quantity' => number_format($allocatedQuantity, 3, '.', ''),
                        'unit_price' => $item->unit_price,
                        'line_total' => $lineTotal,
                    ]);
                    $remainingMilli -= $allocatedMilli;
                }
            }

            $lockedOrder->update(['status' => 'confirmed', 'confirmed_by' => $salesUserId, 'confirmed_at' => now()]);

            return $lockedOrder->fresh('items.product', 'items.allocations.batch');
        }, 3);
    }

    private function eligibleBatches(int $productId)
    {
        return Batch::query()->where('product_id', $productId)
            ->where('quantity_available', '>', 0)
            ->where('expiration_date', '>', now()->toDateString())
            ->orderBy('expiration_date')->orderBy('id');
    }

    private function requestedByProduct(array $items): array
    {
        $requested = [];
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $requested[$productId] = ($requested[$productId] ?? 0) + $this->toMilli($item['quantity']);
        }

        return $requested;
    }

    private function toMilli(int|float|string $quantity): int
    {
        return (int) round((float) $quantity * 1000);
    }
}
