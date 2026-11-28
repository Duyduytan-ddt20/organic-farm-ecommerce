<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\FefoAllocationService;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PaymentController extends Controller
{
    public function __construct(
        protected FefoAllocationService $fefoAllocationService,
        protected PaymentGatewayService $paymentGatewayService
    ) {}

    public function createVnpayPayment(Request $request): JsonResponse
    {
        return $this->createPayment($request, 'vnpay_sandbox');
    }

    public function createMomoPayment(Request $request): JsonResponse
    {
        return $this->createPayment($request, 'momo_sandbox');
    }

    public function confirmDemoPayment(Request $request): JsonResponse
    {
        if (! app()->environment(['local', 'testing'])) {
            abort(404);
        }

        $validated = $request->validate([
            'order_id' => ['required', 'integer'],
        ]);

        $payment = DB::transaction(function () use ($request, $validated): Payment {
            $order = Order::query()
                ->whereKey($validated['order_id'])
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();

            $completedDemoPayment = Payment::query()
                ->where('order_id', $order->id)
                ->where('method', 'mock')
                ->where('status', 'succeeded')
                ->latest('id')
                ->first();

            if ($completedDemoPayment) {
                if (in_array($order->status, ['pending', 'confirmed'], true)) {
                    return $completedDemoPayment;
                }

                throw ValidationException::withMessages([
                    'order_id' => 'Không thể xác nhận demo cho đơn hàng này.',
                ]);
            }

            if ($order->status !== 'pending') {
                throw ValidationException::withMessages([
                    'order_id' => 'Chỉ có thể thanh toán demo cho đơn hàng đang chờ xử lý.',
                ]);
            }

            $pendingPayment = Payment::query()
                ->where('order_id', $order->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($pendingPayment && $pendingPayment->method !== 'mock') {
                throw ValidationException::withMessages([
                    'order_id' => 'Đơn hàng đã có giao dịch thanh toán khác đang chờ xử lý.',
                ]);
            }

            $payment = $pendingPayment ?? Payment::query()->forceCreate([
                'order_id' => $order->id,
                'subscription_id' => null,
                'payment_reference' => 'PAY-'.strtoupper(bin2hex(random_bytes(12))),
                'method' => 'mock',
                'amount' => $order->total_amount,
                'status' => 'pending',
            ]);

            $payment->forceFill([
                'status' => 'succeeded',
                'provider_transaction_id' => 'DEMO-'.$payment->payment_reference,
                'paid_at' => now('UTC'),
            ])->save();

            return $payment;
        });

        $payment->refresh();
        $payment->load('order:id,status');

        return response()->json([
            'message' => 'Thanh toán mô phỏng thành công. Đơn hàng vẫn chờ Sales xác nhận; không có khoản tiền thật được thu.',
            'data' => [
                'payment_reference' => $payment->payment_reference,
                'payment_status' => $payment->status,
                'order_id' => $payment->order_id,
                'order_status' => $payment->order?->status,
                'amount' => $payment->amount,
            ],
        ]);
    }

    public function handleVnpayIpn(Request $request): JsonResponse
    {
        $callback = $this->paymentGatewayService->verifyVnpayIpn($request->all());
        if (! $callback) {
            return response()->json(['RspCode' => '97', 'Message' => 'Invalid signature']);
        }

        $callback['provider'] = 'vnpay_sandbox';

        return $this->processIpn($callback, 'vnpay');
    }

    public function handleMomoIpn(Request $request): JsonResponse
    {
        $callback = $this->paymentGatewayService->verifyMomoIpn($request->all());
        if (! $callback) {
            return response()->json([
                'resultCode' => 97,
                'message' => 'Invalid signature',
                'responseTime' => now()->valueOf(),
            ]);
        }

        $callback['provider'] = 'momo_sandbox';

        return $this->processIpn($callback, 'momo');
    }

    public function showStatus(Request $request, string $paymentReference): JsonResponse
    {
        $payment = Payment::query()
            ->where('payment_reference', $paymentReference)
            ->whereHas('order', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('order:id,status')
            ->firstOrFail();

        return response()->json([
            'data' => [
                'payment_reference' => $payment->payment_reference,
                'payment_status' => $payment->status,
                'order_id' => $payment->order_id,
                'order_status' => $payment->order?->status,
                'amount' => $payment->amount,
            ],
        ]);
    }

    private function createPayment(Request $request, string $method): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
        ]);

        $payment = DB::transaction(function () use ($request, $validated, $method): Payment {
            $order = Order::query()
                ->whereKey($validated['order_id'])
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== 'pending') {
                throw ValidationException::withMessages([
                    'order_id' => 'Chỉ có thể thanh toán đơn hàng đang chờ xử lý.',
                ]);
            }

            $amountMinor = (int) round((float) $order->total_amount * 100);
            if ($method === 'momo_sandbox' && $amountMinor % 100 !== 0) {
                throw ValidationException::withMessages([
                    'order_id' => 'MoMo chỉ hỗ trợ thanh toán số tiền nguyên VND.',
                ]);
            }

            $payment = Payment::query()
                ->where('order_id', $order->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($payment && $payment->method !== $method) {
                throw ValidationException::withMessages([
                    'order_id' => 'Đơn hàng đã có giao dịch thanh toán đang chờ xử lý.',
                ]);
            }

            return $payment ?? Payment::query()->forceCreate([
                'order_id' => $order->id,
                'subscription_id' => null,
                'payment_reference' => 'PAY-'.strtoupper(bin2hex(random_bytes(12))),
                'method' => $method,
                'amount' => $order->total_amount,
                'status' => 'pending',
            ]);
        });

        try {
            $paymentUrl = $this->paymentGatewayService->createPaymentUrl($method, $payment, $request);
        } catch (RuntimeException $exception) {
            $payment->forceFill(['status' => 'failed'])->save();

            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        }

        return response()->json([
            'message' => 'Khởi tạo giao dịch thanh toán thành công.',
            'data' => [
                'payment_reference' => $payment->payment_reference,
                'payment_url' => $paymentUrl,
                'amount' => $payment->amount,
                'method' => $payment->method,
            ],
        ]);
    }

    private function processIpn(array $callback, string $provider): JsonResponse
    {
        try {
            $result = DB::transaction(function () use ($callback): array {
                $payment = Payment::query()
                    ->where('payment_reference', $callback['payment_reference'])
                    ->lockForUpdate()
                    ->first();

                if (! $payment) {
                    return ['code' => '01', 'message' => 'Payment not found'];
                }

                $expectedMethod = $callback['provider'] ?? null;
                if ($expectedMethod && $payment->method !== $expectedMethod) {
                    return ['code' => '04', 'message' => 'Payment provider mismatch'];
                }

                if ($payment->status === 'succeeded') {
                    return ['code' => '02', 'message' => 'Payment already confirmed'];
                }

                if ((int) round((float) $payment->amount * 100) !== $callback['amount_minor']) {
                    return ['code' => '04', 'message' => 'Invalid amount'];
                }

                if (! $callback['succeeded']) {
                    $payment->forceFill([
                        'status' => 'failed',
                        'provider_transaction_id' => $callback['provider_transaction_id'] ?: null,
                    ])->save();

                    return ['code' => '00', 'message' => 'Payment failure recorded'];
                }

                $order = null;
                if ($payment->order_id) {
                    $order = Order::query()
                        ->whereKey($payment->order_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($order->status === 'cancelled') {
                        return ['code' => '04', 'message' => 'Order cancelled'];
                    }
                }

                $payment->forceFill([
                    'status' => 'succeeded',
                    'provider_transaction_id' => $callback['provider_transaction_id'] ?: null,
                    'paid_at' => now('UTC'),
                ])->save();

                if ($order && $order->status === 'pending') {
                    $order->load('items');
                    foreach ($order->items as $item) {
                        $this->fefoAllocationService->allocate($item);
                    }

                    $order->status = 'confirmed';
                    $order->confirmed_at = now();
                    $order->save();
                }

                return ['code' => '00', 'message' => 'Payment confirmed'];
            });
        } catch (Throwable $exception) {
            Log::error('Payment IPN processing failed.', [
                'provider' => $provider,
                'payment_reference' => $callback['payment_reference'] ?? null,
                'exception' => $exception,
            ]);

            return $provider === 'vnpay'
                ? response()->json(['RspCode' => '99', 'Message' => 'System error'], 500)
                : response()->json([
                    'resultCode' => 99,
                    'message' => 'System error',
                    'responseTime' => now()->valueOf(),
                ], 500);
        }

        if ($provider === 'vnpay') {
            return response()->json([
                'RspCode' => $result['code'],
                'Message' => $result['message'],
            ]);
        }

        return response()->json([
            'resultCode' => $result['code'] === '00' || $result['code'] === '02' ? 0 : (int) $result['code'],
            'message' => $result['message'],
            'responseTime' => now()->valueOf(),
        ]);
    }
}
