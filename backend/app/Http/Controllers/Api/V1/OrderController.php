<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\FefoAllocationService;
use App\Services\ShippingQuoteTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(
        Request $request,
        FefoAllocationService $fefo,
        ShippingQuoteTokenService $quoteTokens,
    ): JsonResponse {
        $data = $request->validate([
            'recipient_name' => ['required', 'string', 'max:100'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'delivery_address' => ['required', 'string', 'max:500'],
            'quote_token' => ['required', 'string', 'max:8192'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        $shippingQuote = $quoteTokens->verify(
            $data['quote_token'],
            $data['delivery_address'],
            (int) $request->user()->getAuthIdentifier(),
        );
        $shippingFeeCents = $this->toCents($shippingQuote['shipping_fee']);

        $order = DB::transaction(function () use ($request, $data, $fefo, $shippingFeeCents): Order {
            $totalCents = $shippingFeeCents;

            $order = Order::query()->create([
                'user_id' => $request->user()->id,
                'order_code' => 'ORD-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6)),
                'order_type' => 'retail',
                'recipient_name' => $data['recipient_name'],
                'recipient_phone' => $data['recipient_phone'],
                'delivery_address' => $data['delivery_address'],
                'shipping_fee' => $this->fromCents($shippingFeeCents),
                'total_amount' => '0.00',
                'status' => 'pending',
            ]);

            foreach ($data['items'] as $itemData) {
                $product = Product::query()
                    ->whereKey($itemData['product_id'])
                    ->where('is_active', true)
                    ->first();

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'Sản phẩm không còn được kinh doanh.',
                    ]);
                }

                $fefo->ensureAvailable((int) $product->id, $itemData['quantity']);
                $quantityCents = $this->toCents($itemData['quantity']);
                $unitPriceCents = $this->toCents($product->base_price);
                $totalCents += $quantityCents * $unitPriceCents / 100;

                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $this->fromCents($quantityCents),
                    'unit_price' => $this->fromCents($unitPriceCents),
                ]);
            }

            $order->update(['total_amount' => $this->fromCents($totalCents)]);

            return $order;
        });

        return response()->json([
            'message' => 'Tạo đơn hàng thành công.',
            'data' => $this->serializeOrder($order),
        ], 201);
    }

    public function customerIndex(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['items.product', 'items.details.batch'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $orders->map(fn (Order $order) => $this->serializeOrder($order)),
        ]);
    }

    public function salesIndex(): JsonResponse
    {
        $orders = Order::query()
            ->with(['user:id,full_name,email', 'items.product', 'items.details.batch'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $orders->map(fn (Order $order) => $this->serializeOrder($order)),
        ]);
    }

    public function confirm(Request $request, Order $order, FefoAllocationService $fefo): JsonResponse
    {
        $order = DB::transaction(function () use ($order, $fefo): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Chỉ có thể xác nhận đơn đang chờ xử lý.',
                ]);
            }

            $locked->load('items');
            foreach ($locked->items as $item) {
                $fefo->allocate($item);
            }

            $locked->status = 'confirmed';
            $locked->confirmed_at = now();
            $locked->save();
            $locked->load(['user:id,full_name,email', 'items.product', 'items.details.batch']);

            return $locked;
        });

        return response()->json([
            'message' => 'Đã xác nhận đơn và phân bổ kho FEFO.',
            'data' => $this->serializeOrder($order),
        ]);
    }

    private function serializeOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_code' => $order->order_code,
            'recipient_name' => $order->recipient_name,
            'recipient_phone' => $order->recipient_phone,
            'delivery_address' => $order->delivery_address,
            'shipping_fee' => number_format((float) $order->shipping_fee, 2, '.', ''),
            'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
            'status' => $order->status,
            'confirmed_at' => $order->confirmed_at?->format('Y-m-d H:i:s'),
            'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
            'customer' => $order->user ? [
                'id' => $order->user->id,
                'full_name' => $order->user->full_name,
                'email' => $order->user->email,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'quantity' => number_format((float) $item->quantity, 2, '.', ''),
                'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                'allocated_batches' => $item->details->map(fn ($detail) => [
                    'batch_id' => $detail->batch_id,
                    'batch_code' => $detail->batch?->batch_code,
                    'quantity' => number_format((float) $detail->quantity, 2, '.', ''),
                    'allocated_at' => $detail->allocated_at?->format('Y-m-d H:i:s'),
                ]),
            ]),
        ];
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
