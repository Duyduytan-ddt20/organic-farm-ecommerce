<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Customer', 'Staff', 'Sales', 'Admin'] as $roleName) {
            Role::query()->create(['role_name' => $roleName]);
        }

        DB::table('categories')->insert([
            'id' => 1,
            'name' => 'Củ quả',
            'slug' => 'cu-qua',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('products')->insert([
            'id' => 1,
            'category_id' => 1,
            'name' => 'Cà chua hữu cơ',
            'slug' => 'ca-chua-huu-co',
            'base_price' => 38000,
            'unit' => 'kg',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config([
            'services.vnpay.tmn_code' => 'TESTCODE',
            'services.vnpay.hash_secret' => 'test-vnp-secret',
            'services.vnpay.url' => 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html',
            'services.vnpay.return_url' => 'http://localhost:5173/thanh-toan/ket-qua',
            'services.momo.partner_code' => 'MOMOTEST',
            'services.momo.access_key' => 'test-access-key',
            'services.momo.secret_key' => 'test-momo-secret',
            'services.momo.url' => 'https://test-payment.momo.vn/v2/gateway/api/create',
            'services.momo.ipn_url' => 'https://example.test/api/v1/payments/momo-ipn',
            'services.momo.return_url' => 'http://localhost:5173/thanh-toan/ket-qua',
        ]);
    }

    public function test_customer_can_create_vnpay_payment_and_ipn_confirms_order_with_fefo(): void
    {
        $batch = $this->batch(5);
        $token = $this->customerToken();
        $orderId = $this->createOrder($token, 2);

        $payment = $this->withToken($token)
            ->postJson('/api/v1/payments/vnpay/create', ['order_id' => $orderId])
            ->assertOk()
            ->json('data');

        $this->assertSame('vnpay_sandbox', $payment['method']);
        $this->assertStringStartsWith(
            'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html?',
            $payment['payment_url']
        );
        $this->withToken($token)
            ->getJson('/api/v1/payments/'.$payment['payment_reference'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'pending');

        $callback = [
            'vnp_Amount' => (string) ((int) round((float) $payment['amount'] * 100)),
            'vnp_TmnCode' => 'TESTCODE',
            'vnp_TxnRef' => $payment['payment_reference'],
            'vnp_TransactionNo' => 'VNP-TEST-1',
            'vnp_ResponseCode' => '00',
            'vnp_TransactionStatus' => '00',
        ];
        ksort($callback);
        $callback['vnp_SecureHash'] = hash_hmac(
            'sha512',
            http_build_query($callback),
            'test-vnp-secret'
        );

        $invalidCallback = $callback;
        $invalidCallback['vnp_SecureHash'] = str_repeat('0', 128);
        $this->postJson('/api/v1/payments/vnpay-ipn', $invalidCallback)
            ->assertOk()
            ->assertJsonPath('RspCode', '97');

        $this->postJson('/api/v1/payments/vnpay-ipn', $callback)
            ->assertOk()
            ->assertJsonPath('RspCode', '00');

        $this->assertDatabaseHas('payments', [
            'payment_reference' => $payment['payment_reference'],
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('batches', ['id' => $batch->id, 'available_quantity' => '3.00']);
        $this->assertDatabaseHas('order_details', ['batch_id' => $batch->id, 'quantity' => '2.00']);
    }

    public function test_fefo_failure_rolls_back_payment_and_order_confirmation(): void
    {
        $token = $this->customerToken();
        $orderId = $this->createOrder($token, 2);
        $batch = Batch::query()->latest('id')->firstOrFail();
        $batch->available_quantity = '1.00';
        $batch->save();

        $payment = $this->withToken($token)
            ->postJson('/api/v1/payments/vnpay/create', ['order_id' => $orderId])
            ->assertOk()
            ->json('data');

        $callback = [
            'vnp_Amount' => (string) ((int) round((float) $payment['amount'] * 100)),
            'vnp_TmnCode' => 'TESTCODE',
            'vnp_TxnRef' => $payment['payment_reference'],
            'vnp_TransactionNo' => 'VNP-TEST-ROLLBACK',
            'vnp_ResponseCode' => '00',
            'vnp_TransactionStatus' => '00',
        ];
        ksort($callback);
        $callback['vnp_SecureHash'] = hash_hmac(
            'sha512',
            http_build_query($callback),
            'test-vnp-secret'
        );

        $this->postJson('/api/v1/payments/vnpay-ipn', $callback)->assertServerError();

        $this->assertDatabaseHas('payments', [
            'payment_reference' => $payment['payment_reference'],
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'pending']);
        $this->assertDatabaseHas('batches', ['id' => $batch->id, 'available_quantity' => '1.00']);
    }

    public function test_customer_can_create_and_confirm_momo_sandbox_payment(): void
    {
        Http::fake([
            'https://test-payment.momo.vn/*' => Http::response([
                'resultCode' => 0,
                'payUrl' => 'https://test-payment.momo.vn/pay/test',
            ]),
        ]);

        $token = $this->customerToken();
        $orderId = $this->createOrder($token, 1);
        $payment = $this->withToken($token)
            ->postJson('/api/v1/payments/momo/create', ['order_id' => $orderId])
            ->assertOk()
            ->json('data');

        $this->assertSame('momo_sandbox', $payment['method']);
        $this->assertSame('https://test-payment.momo.vn/pay/test', $payment['payment_url']);

        Http::assertSent(function ($request) use ($payment, $orderId): bool {
            $signatureData = [
                'accessKey' => 'test-access-key',
                'amount' => (string) ((int) round((float) $payment['amount'])),
                'extraData' => '',
                'ipnUrl' => 'https://example.test/api/v1/payments/momo-ipn',
                'orderId' => $payment['payment_reference'],
                'orderInfo' => 'Thanh toan don hang '.$orderId,
                'partnerCode' => 'MOMOTEST',
                'redirectUrl' => 'http://localhost:5173/thanh-toan/ket-qua',
                'requestId' => $payment['payment_reference'],
                'requestType' => 'captureWallet',
            ];
            $rawSignature = implode('&', array_map(
                fn (string $key, string $value) => $key.'='.$value,
                array_keys($signatureData),
                array_values($signatureData)
            ));

            return $request->url() === 'https://test-payment.momo.vn/v2/gateway/api/create'
                && $request['signature'] === hash_hmac('sha256', $rawSignature, 'test-momo-secret');
        });

        $callback = [
            'accessKey' => 'test-access-key',
            'amount' => (int) round((float) $payment['amount']),
            'extraData' => '',
            'message' => 'Successful.',
            'orderId' => $payment['payment_reference'],
            'orderInfo' => 'Thanh toan don hang '.$orderId,
            'orderType' => 'momo_wallet',
            'partnerCode' => 'MOMOTEST',
            'payType' => 'qr',
            'requestId' => $payment['payment_reference'],
            'responseTime' => now()->valueOf(),
            'resultCode' => 0,
            'transId' => 123456,
        ];
        $rawSignature = implode('&', array_map(
            fn (string $key, mixed $value) => $key.'='.(string) $value,
            array_keys($callback),
            array_values($callback)
        ));
        $callback['signature'] = hash_hmac('sha256', $rawSignature, 'test-momo-secret');

        $this->postJson('/api/v1/payments/momo-ipn', $callback)
            ->assertOk()
            ->assertJsonPath('resultCode', 0);

        $this->assertDatabaseHas('payments', [
            'payment_reference' => $payment['payment_reference'],
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'confirmed']);
    }

    private function createOrder(string $token, int $quantity): int
    {
        $this->batch(10);

        return (int) $this->withToken($token)
            ->postJson('/api/v1/customer/orders', [
                'recipient_name' => 'Khách hàng',
                'recipient_phone' => '0900000000',
                'delivery_address' => 'Hà Nội',
                'items' => [['product_id' => 1, 'quantity' => $quantity]],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function batch(int $available): Batch
    {
        static $batchNumber = 0;
        $batchNumber++;

        return Batch::query()->create([
            'product_id' => 1,
            'batch_code' => 'PAY-'.$batchNumber,
            'growing_location' => 'Nhà màng',
            'harvested_at' => now()->subDay()->toDateString(),
            'expires_at' => now()->addDays(30),
            'received_quantity' => $available,
            'available_quantity' => $available,
            'status' => 'available',
            'qr_token' => 'payment-qr-'.$batchNumber,
        ]);
    }

    private function customerToken(): string
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('role_name', 'Customer')->value('id'),
            'password' => '12345678',
        ]);

        return $user->createToken('payment-test')->plainTextToken;
    }
}
