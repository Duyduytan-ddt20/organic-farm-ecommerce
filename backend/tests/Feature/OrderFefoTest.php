<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderFefoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Idempotency-Key', Str::uuid()->toString());

        config([
            'services.shipping.external_enabled' => true,
            'services.shipping.provider' => 'nominatim',
            'services.shipping.routing_provider' => 'osrm',
            'services.shipping.farm_latitude' => 10.77197,
            'services.shipping.farm_longitude' => 106.70135,
            'services.shipping.rate_per_km' => 5000,
            'services.shipping.geocode_cache_ttl' => 3600,
            'services.shipping.quote_ttl' => 600,
            'services.shipping.connect_timeout' => 1,
            'services.shipping.timeout' => 2,
            'services.nominatim.base_url' => 'https://nominatim.test',
            'services.nominatim.user_agent' => 'OrganicFarmEcommerceTest/1.0',
            'services.osrm.base_url' => 'https://osrm.test',
        ]);
        Cache::flush();
        Http::preventStrayRequests();

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
    }

    public function test_customer_can_checkout_and_sales_confirm_allocates_fefo(): void
    {
        $this->fakeShipping();
        $first = $this->batch('CC-A01', 3, '2026-12-01');
        $second = $this->batch('CC-B02', 25, '2026-12-10');
        $customer = $this->user('Customer');
        $sales = $this->user('Sales');
        $quoteToken = $this->quoteToken($customer);

        $create = $this->withToken($customer->createToken('test')->plainTextToken)
            ->postJson('/api/v1/customer/orders', $this->checkoutData($quoteToken, 26170, 5))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.shipping_fee', '26170.00')
            ->assertJsonPath('data.total_amount', '216170.00');

        $orderId = $create->json('data.id');
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'shipping_fee' => '26170.00',
            'total_amount' => '216170.00',
        ]);

        $salesToken = $sales->createToken('test')->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($salesToken)
            ->getJson('/api/v1/sales/orders')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.planned_batches.0.batch_code', 'CC-A01')
            ->assertJsonPath('data.0.items.0.planned_batches.0.quantity', '3.00')
            ->assertJsonPath('data.0.items.0.planned_batches.1.batch_code', 'CC-B02')
            ->assertJsonPath('data.0.items.0.planned_batches.1.quantity', '2.00');

        app('auth')->forgetGuards();
        $this->withToken($salesToken)
            ->postJson('/api/v1/sales/orders/'.$orderId.'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonCount(2, 'data.items.0.allocated_batches');

        $first->refresh();
        $second->refresh();
        $this->assertSame('0.00', number_format((float) $first->available_quantity, 2, '.', ''));
        $this->assertSame('23.00', number_format((float) $second->available_quantity, 2, '.', ''));
        $this->assertSame('closed', $first->status);
        $this->assertDatabaseHas('order_details', [
            'batch_id' => $first->id,
            'quantity' => '3.00',
        ]);
    }

    public function test_customer_can_check_cart_stock_without_allocating_or_changing_inventory(): void
    {
        $validBatch = $this->batch('CHECK-VALID', 3, now()->addDays(2)->toDateString());
        $expiredBatch = $this->batch('CHECK-EXPIRED', 10, now()->subDay()->toDateString());
        $emptyBatch = $this->batch('CHECK-EMPTY', 0, now()->addDay()->toDateString());
        $customer = $this->user('Customer');
        $token = $customer->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/customer/cart/check-stock', [
                'product_id' => 1,
                'quantity' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.product_id', 1)
            ->assertJsonPath('data.quantity', 3);

        $this->withToken($token)
            ->postJson('/api/v1/customer/cart/check-stock', [
                'product_id' => 1,
                'quantity' => 4,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_details', 0);
        $this->assertSame('3.00', number_format((float) $validBatch->refresh()->available_quantity, 2, '.', ''));
        $this->assertSame('10.00', number_format((float) $expiredBatch->refresh()->available_quantity, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $emptyBatch->refresh()->available_quantity, 2, '.', ''));
    }

    public function test_checkout_rejects_a_missing_quote_token_without_creating_an_order_or_changing_stock(): void
    {
        Http::fake();
        $batch = $this->batch('CC-NO-QUOTE', 10, '2026-12-10');
        $customer = $this->user('Customer');

        $this->withToken($customer->createToken('test')->plainTextToken)
            ->postJson('/api/v1/customer/orders', $this->checkoutData())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quote_token']);

        $this->assertNoOrderOrStockChange($batch);
        Http::assertNothingSent();
    }

    public function test_checkout_rejects_an_invalid_quote_token_without_creating_an_order_or_changing_stock(): void
    {
        $this->fakeShipping();
        $batch = $this->batch('CC-INVALID-QUOTE', 10, '2026-12-10');
        $customer = $this->user('Customer');
        $quoteToken = $this->quoteToken($customer);
        $data = $this->checkoutData($quoteToken);
        $data['quote_token'] = 'invalid-quote-token';

        $this->withToken($customer->createToken('test')->plainTextToken)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quote_token']);

        $this->assertNoOrderOrStockChange($batch);
    }

    public function test_checkout_rejects_an_expired_quote_token_without_creating_an_order_or_changing_stock(): void
    {
        $this->fakeShipping();
        $batch = $this->batch('CC-EXPIRED-QUOTE', 10, '2026-12-10');
        $customer = $this->user('Customer');
        $quoteToken = $this->quoteToken($customer);
        $this->travel(601)->seconds();

        $this->withToken($customer->createToken('test')->plainTextToken)
            ->postJson('/api/v1/customer/orders', $this->checkoutData($quoteToken))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quote_token']);

        $this->assertNoOrderOrStockChange($batch);
    }

    public function test_checkout_rejects_a_quote_for_another_address_without_creating_an_order_or_changing_stock(): void
    {
        $this->fakeShipping();
        $batch = $this->batch('CC-ADDRESS-MISMATCH', 10, '2026-12-10');
        $customer = $this->user('Customer');
        $quoteToken = $this->quoteToken($customer);
        $data = $this->checkoutData($quoteToken);
        $data['delivery_address'] = '200 Other Synthetic Street';

        $this->withToken($customer->createToken('test')->plainTextToken)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quote_token']);

        $this->assertNoOrderOrStockChange($batch);
    }

    public function test_checkout_rejects_a_quote_issued_to_another_customer(): void
    {
        $this->fakeShipping();
        $batch = $this->batch('CC-OTHER-CUSTOMER', 10, '2026-12-10');
        $customer = $this->user('Customer');
        $otherCustomer = $this->user('Customer');
        $quoteToken = $this->quoteToken($customer);

        app('auth')->forgetGuards();
        $this->withToken($otherCustomer->createToken('test')->plainTextToken)
            ->postJson('/api/v1/customer/orders', $this->checkoutData($quoteToken))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quote_token']);

        $this->assertNoOrderOrStockChange($batch);
    }

    public function test_forged_client_shipping_fee_does_not_change_the_fee_saved_from_a_valid_quote(): void
    {
        $this->fakeShipping();
        $this->batch('CC-FORGED-QUOTE', 10, '2026-12-10');
        $customer = $this->user('Customer');
        $quoteToken = $this->quoteToken($customer);

        $this->withToken($customer->createToken('test')->plainTextToken)
            ->postJson('/api/v1/customer/orders', $this->checkoutData($quoteToken, 999999))
            ->assertCreated()
            ->assertJsonPath('data.shipping_fee', '26170.00');

        $this->assertDatabaseHas('orders', [
            'shipping_fee' => '26170.00',
            'total_amount' => '64170.00',
        ]);
    }

    public function test_shipping_failure_does_not_create_order_or_change_stock(): void
    {
        $batch = $this->batch('CC-SHIPPING-ERROR', 10, '2026-12-10');
        $customer = $this->user('Customer');
        Http::fake([
            'https://nominatim.test/search*' => Http::response(['upstream error'], 503),
        ]);

        $this->withToken($customer->createToken('test')->plainTextToken)
            ->postJson('/api/v1/shipping/calculate', [
                'delivery_address' => '100 Demo Street, Example District',
            ])
            ->assertStatus(502);

        $this->withToken($customer->createToken('test')->plainTextToken)
            ->postJson('/api/v1/customer/orders', $this->checkoutData())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quote_token']);

        $this->assertNoOrderOrStockChange($batch);
    }

    public function test_checkout_rejects_quantity_without_enough_available_stock(): void
    {
        $this->fakeShipping();
        $this->batch('CC-SMALL', 2, '2026-12-10');
        $customer = $this->user('Customer');
        $quoteToken = $this->quoteToken($customer);

        $this->withToken($customer->createToken('test')->plainTextToken)
            ->postJson('/api/v1/customer/orders', $this->checkoutData($quoteToken, null, 3))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_checkout_requires_an_idempotency_key_header(): void
    {
        $customer = $this->user('Customer');

        $this->withToken($customer->createToken('test')->plainTextToken)
            ->withHeader('Idempotency-Key', '')
            ->postJson('/api/v1/customer/orders', $this->checkoutData())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_idempotency_keys', 0);
    }

    public function test_repeated_checkout_with_the_same_key_replays_the_original_order(): void
    {
        $this->fakeShipping();
        $this->batch('CC-IDEMPOTENT', 10, '2026-12-10');
        $customer = $this->user('Customer');
        $quoteToken = $this->quoteToken($customer);
        $key = Str::uuid()->toString();
        $data = $this->checkoutData($quoteToken);
        $token = $customer->createToken('test')->plainTextToken;

        $first = $this->withToken($token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertCreated();
        $replay = $this->withToken($token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('order_idempotency_keys', 1);
    }

    public function test_overlapping_checkout_with_the_same_key_cannot_create_a_second_order(): void
    {
        $this->fakeShipping();
        $this->batch('CC-IDEMPOTENCY-OVERLAP', 10, '2026-12-10');
        $customer = $this->user('Customer');
        $quoteToken = $this->quoteToken($customer);
        $key = Str::uuid()->toString();
        $data = $this->checkoutData($quoteToken);
        $token = $customer->createToken('test')->plainTextToken;
        $overlappingResponse = null;
        $overlapTriggered = false;

        DB::listen(function ($query) use (&$overlapTriggered, &$overlappingResponse, $data, $key, $token): void {
            if ($overlapTriggered || ! preg_match('/^insert into ["`]?order_idempotency_keys/i', $query->sql)) {
                return;
            }

            $overlapTriggered = true;
            $overlappingResponse = $this->withToken($token)
                ->withHeader('Idempotency-Key', $key)
                ->postJson('/api/v1/customer/orders', $data);
        });

        $this->withToken($token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertCreated();

        $this->assertTrue($overlapTriggered);
        $this->assertNotNull($overlappingResponse);
        $overlappingResponse->assertStatus(503)->assertHeader('Retry-After', '1');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('order_idempotency_keys', 1);

        $this->withToken($token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_reusing_an_idempotency_key_with_different_checkout_data_returns_conflict(): void
    {
        $this->fakeShipping();
        $this->batch('CC-IDEMPOTENCY-CONFLICT', 10, '2026-12-10');
        $customer = $this->user('Customer');
        $quoteToken = $this->quoteToken($customer);
        $key = Str::uuid()->toString();
        $data = $this->checkoutData($quoteToken);
        $token = $customer->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertCreated();

        $data['items'][0]['quantity'] = 2;
        $this->withToken($token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertConflict();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_failed_order_transaction_does_not_keep_an_idempotency_record_and_can_be_retried(): void
    {
        $this->fakeShipping();
        $this->batch('CC-IDEMPOTENCY-RETRY', 10, '2026-12-10');
        $customer = $this->user('Customer');
        $quoteToken = $this->quoteToken($customer);
        $key = Str::uuid()->toString();
        $data = $this->checkoutData($quoteToken);
        $token = $customer->createToken('test')->plainTextToken;

        DB::table('products')->where('id', 1)->update(['is_active' => 0]);
        $this->withToken($token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_idempotency_keys', 0);

        DB::table('products')->where('id', 1)->update(['is_active' => 1]);
        $this->withToken($token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertCreated();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_idempotency_keys', 1);
    }

    public function test_successful_checkout_can_be_replayed_after_its_quote_expires(): void
    {
        $this->fakeShipping();
        $this->batch('CC-IDEMPOTENCY-EXPIRED-QUOTE', 10, '2026-12-10');
        $customer = $this->user('Customer');
        $quoteToken = $this->quoteToken($customer);
        $key = Str::uuid()->toString();
        $data = $this->checkoutData($quoteToken);
        $token = $customer->createToken('test')->plainTextToken;

        $first = $this->withToken($token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertCreated();

        $this->travel(601)->seconds();
        $replay = $this->withToken($token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_another_customer_cannot_replay_an_idempotency_key_or_quote_from_a_different_customer(): void
    {
        $this->fakeShipping();
        $this->batch('CC-IDEMPOTENCY-USER-SCOPE', 10, '2026-12-10');
        $customer = $this->user('Customer');
        $otherCustomer = $this->user('Customer');
        $quoteToken = $this->quoteToken($customer);
        $key = Str::uuid()->toString();
        $data = $this->checkoutData($quoteToken);
        $token = $customer->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertCreated();

        app('auth')->forgetGuards();
        $this->withToken($otherCustomer->createToken('test')->plainTextToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/orders', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quote_token']);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', ['user_id' => $customer->id]);
        $this->assertDatabaseMissing('orders', ['user_id' => $otherCustomer->id]);
    }

    private function fakeShipping(float $distanceMeters = 5234): void
    {
        Http::fake([
            'https://nominatim.test/search*' => Http::response([
                ['lat' => '10.8', 'lon' => '106.75'],
            ], 200),
            'https://osrm.test/route/v1/driving/*' => Http::response([
                'code' => 'Ok',
                'routes' => [['distance' => $distanceMeters]],
            ], 200),
        ]);
    }

    private function quoteToken(User $customer, string $address = '100 Demo Street, Example District'): string
    {
        $response = $this->withToken($customer->createToken('quote-test')->plainTextToken)
            ->postJson('/api/v1/shipping/calculate', ['delivery_address' => $address])
            ->assertOk()
            ->assertJsonStructure(['quote_token']);

        $token = $response->json('quote_token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutData(
        ?string $quoteToken = null,
        ?int $shippingFee = null,
        int $quantity = 1,
        string $address = '100 Demo Street, Example District',
    ): array {
        $data = [
            'recipient_name' => 'Khách thử nghiệm',
            'recipient_phone' => '0900000000',
            'delivery_address' => $address,
            'items' => [['product_id' => 1, 'quantity' => $quantity]],
        ];

        if ($quoteToken !== null) {
            $data['quote_token'] = $quoteToken;
        }

        if ($shippingFee !== null) {
            $data['shipping_fee'] = $shippingFee;
        }

        return $data;
    }

    private function assertNoOrderOrStockChange(Batch $batch): void
    {
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_details', 0);
        $this->assertDatabaseCount('order_idempotency_keys', 0);
        $this->assertSame('10.00', number_format((float) $batch->refresh()->available_quantity, 2, '.', ''));
    }

    private function batch(string $code, int $available, string $expires): Batch
    {
        return Batch::query()->create([
            'product_id' => 1,
            'batch_code' => $code,
            'growing_location' => 'Nhà màng',
            'harvested_at' => '2026-11-20',
            'expires_at' => $expires.' 08:00:00',
            'received_quantity' => 30,
            'available_quantity' => $available,
            'status' => 'available',
            'qr_token' => 'qr-'.$code,
        ]);
    }

    private function user(string $roleName): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('role_name', $roleName)->value('id'),
            'password' => '12345678',
        ]);
    }
}
