<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderIdempotencyKey;
use App\Models\Product;
use App\Services\FefoAllocationService;
use App\Services\ShippingQuoteTokenService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function checkCartStock(Request $request, FefoAllocationService $fefo): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        $product = Product::query()
            ->whereKey($data['product_id'])
            ->where('is_active', true)
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => 'Sản phẩm không còn được kinh doanh.',
            ]);
        }

        if ((float) $data['quantity'] > 0) {
            $fefo->ensureAvailable((int) $product->id, $data['quantity']);
        }

        return response()->json([
            'message' => 'Tồn kho khả dụng đủ.',
            'data' => [
                'product_id' => $product->id,
                'quantity' => $data['quantity'],
            ],
        ]);
    }

    public function store(
        Request $request,
        FefoAllocationService $fefo,
        ShippingQuoteTokenService $quoteTokens,
    ): JsonResponse {
        $keyData = Validator::make([
            'idempotency_key' => $request->header('Idempotency-Key'),
        ], [
            'idempotency_key' => ['required', 'string', 'min:16', 'max:255', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ], [], ['idempotency_key' => 'Idempotency-Key header'])->validate();
        $data = $request->validate([
            'recipient_name' => ['required', 'string', 'max:100'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'delivery_address' => ['required', 'string', 'max:500'],
            'quote_token' => ['required', 'string', 'max:8192'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        $keyHash = hash('sha256', trim($keyData['idempotency_key']));
        $requestHash = $this->checkoutRequestHash($data);
        $userId = (int) $request->user()->getAuthIdentifier();

        try {
            $result = DB::transaction(function () use ($data, $fefo, $quoteTokens, $keyHash, $requestHash, $userId): array {
                $idempotencyRecord = OrderIdempotencyKey::query()->create([
                    'user_id' => $userId,
                    'key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                ]);

                $shippingQuote = $quoteTokens->verify(
                    $data['quote_token'],
                    $data['delivery_address'],
                    $userId,
                );
                $shippingFeeCents = $this->toCents($shippingQuote['shipping_fee']);
                $totalCents = $shippingFeeCents;

                $order = Order::query()->create([
                    'user_id' => $userId,
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
                $responseBody = [
                    'message' => 'Tạo đơn hàng thành công.',
                    'data' => $this->serializeOrder($order->load(['items.product', 'items.details.batch'])),
                ];

                $idempotencyRecord->update([
                    'order_id' => $order->id,
                    'response_status' => 201,
                    'response_body' => $responseBody,
                ]);

                return ['body' => $responseBody, 'status' => 201];
            }, 3);

            return response()->json($result['body'], $result['status']);
        } catch (QueryException $exception) {
            $existingRecord = OrderIdempotencyKey::query()
                ->where('user_id', $userId)
                ->where('key_hash', $keyHash)
                ->first();

            if ($existingRecord) {
                if (! hash_equals($existingRecord->request_hash, $requestHash)) {
                    return response()->json([
                        'message' => 'Idempotency-Key đã được dùng cho nội dung checkout khác.',
                    ], 409);
                }

                if ($existingRecord->response_status && $existingRecord->response_body) {
                    return response()
                        ->json($existingRecord->response_body, $existingRecord->response_status)
                        ->header('Idempotency-Replayed', 'true');
                }

                return response()->json([
                    'message' => 'Checkout cùng khóa vẫn đang được xử lý. Vui lòng thử lại với cùng Idempotency-Key.',
                ], 503)->header('Retry-After', '1');
            }

            if ($this->isRetryableDatabaseContention($exception)) {
                return response()->json([
                    'message' => 'Checkout đang được xử lý đồng thời. Vui lòng thử lại với cùng Idempotency-Key.',
                ], 503)->header('Retry-After', '1');
            }

            throw $exception;
        }
    }

    public function customerIndex(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['items.product', 'items.details.batch', 'payments'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $orders->map(fn (Order $order) => $this->serializeOrder($order)),
        ]);
    }

    public function salesIndex(FefoAllocationService $fefo): JsonResponse
    {
        $orders = Order::query()
            ->with(['user:id,full_name,email', 'items.product', 'items.details.batch', 'payments'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get();

        foreach ($orders->where('status', 'pending') as $order) {
            $plannedBatches = $fefo->previewForOrder($order);
            foreach ($order->items as $item) {
                $item->setAttribute('planned_batches', $plannedBatches[$item->id] ?? []);
            }
        }

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
            $locked->load(['user:id,full_name,email', 'items.product', 'items.details.batch', 'payments']);

            return $locked;
        });

        return response()->json([
            'message' => 'Đã xác nhận đơn và phân bổ kho FEFO.',
            'data' => $this->serializeOrder($order),
        ]);
    }

    private function serializeOrder(Order $order): array
    {
        $latestPayment = $order->payments->sortByDesc('id')->first();

        return [
            'id' => $order->id,
            'order_code' => $order->order_code,
            'recipient_name' => $order->recipient_name,
            'recipient_phone' => $order->recipient_phone,
            'delivery_address' => $order->delivery_address,
            'shipping_fee' => number_format((float) $order->shipping_fee, 2, '.', ''),
            'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
            'status' => $order->status,
            'payment_status' => $latestPayment?->status,
            'payment_method' => $latestPayment?->method,
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
                'planned_batches' => $item->getAttribute('planned_batches') ?? [],
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

    /**
     * The quote token is an authorization proof, not order content. Excluding it
     * allows an identical checkout in another tab to replay the original order.
     *
     * @param  array<string, mixed>  $data
     */
    private function checkoutRequestHash(array $data): string
    {
        unset($data['quote_token'], $data['shipping_fee']);

        $data['items'] = array_map(
            fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'quantity' => number_format((float) $item['quantity'], 2, '.', ''),
            ],
            $data['items'],
        );
        usort($data['items'], fn (array $left, array $right): int => [$left['product_id'], $left['quantity']] <=> [$right['product_id'], $right['quantity']]);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function isRetryableDatabaseContention(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($sqlState, ['40001', '40P01', '55P03'], true)
            || ($sqlState === 'HY000' && in_array($driverCode, [5, 6, 1205, 1213], true));
    }
}
