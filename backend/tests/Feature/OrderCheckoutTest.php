<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_creates_pending_order_items_without_decrementing_stock(): void
    {
        [$product, $firstBatch, $secondBatch] = $this->tomatoStock();

        $response = $this->postJson('/api/v1/orders', $this->payload([
            ['product_id' => $product->id, 'quantity' => 10],
        ]));

        $response->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.items.0.quantity', '10.000');
        $this->assertDatabaseHas('order_items', ['order_id' => $response->json('data.id'), 'product_id' => $product->id, 'quantity' => 10]);
        $this->assertDatabaseMissing('order_details', ['order_item_id' => $response->json('data.items.0.id')]);
        $this->assertSame('3.000', $firstBatch->fresh()->quantity_available);
        $this->assertSame('7.000', $secondBatch->fresh()->quantity_available);
    }

    public function test_sales_confirmation_allocates_by_fefo_and_decrements_each_batch(): void
    {
        [$product, $firstBatch, $secondBatch, $expiredBatch, $emptyBatch] = $this->tomatoStock();
        $order = $this->createOrder([['product_id' => $product->id, 'quantity' => 10]]);
        $salesUser = User::factory()->create(['role_name' => 'Sales']);

        $confirmUrl = '/api/v1/sales/orders/'.$order->id.'/confirm';
        $this->actingAs($salesUser, 'sanctum')
            ->postJson($confirmUrl)
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.items.0.allocations.0.batch_id', $firstBatch->id)
            ->assertJsonPath('data.items.0.allocations.0.allocated_quantity', '3.000')
            ->assertJsonPath('data.items.0.allocations.1.batch_id', $secondBatch->id)
            ->assertJsonPath('data.items.0.allocations.1.allocated_quantity', '7.000');

        $this->assertSame('0.000', $firstBatch->fresh()->quantity_available);
        $this->assertSame('0.000', $secondBatch->fresh()->quantity_available);
        $this->assertSame('20.000', $expiredBatch->fresh()->quantity_available);
        $this->assertSame('0.000', $emptyBatch->fresh()->quantity_available);
        $this->assertDatabaseCount('order_details', 2);
        $this->postJson($confirmUrl)->assertOk();
        $this->assertDatabaseCount('order_details', 2);
    }

    public function test_checkout_rejects_insufficient_valid_stock_without_creating_an_order(): void
    {
        [$product] = $this->tomatoStock();

        $this->postJson('/api/v1/orders', $this->payload([
            ['product_id' => $product->id, 'quantity' => 11],
        ]))
            ->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('requested', 11)
            ->assertJsonPath('available', 10);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_confirmation_rolls_back_all_allocations_when_a_later_item_lacks_stock(): void
    {
        [$tomato, $tomatoFirst, $tomatoSecond] = $this->tomatoStock();
        $cucumber = $this->product('Cucumber', 'CUC-01', 25000);
        $cucumberBatch = $this->batch($cucumber, 'CUC-BATCH-1', now()->addDays(4)->toDateString(), 2, 2);
        $order = $this->createOrder([
            ['product_id' => $tomato->id, 'quantity' => 4],
            ['product_id' => $cucumber->id, 'quantity' => 2],
        ]);

        // Simulate another confirmed order consuming cucumber after this checkout was created.
        $cucumberBatch->update(['quantity_available' => 1]);
        $salesUser = User::factory()->create(['role_name' => 'Sales']);

        $this->actingAs($salesUser, 'sanctum')
            ->postJson('/api/v1/sales/orders/'.$order->id.'/confirm')
            ->assertStatus(409)
            ->assertJsonPath('status', 'error');

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('3.000', $tomatoFirst->fresh()->quantity_available);
        $this->assertSame('7.000', $tomatoSecond->fresh()->quantity_available);
        $this->assertSame('1.000', $cucumberBatch->fresh()->quantity_available);
        $this->assertDatabaseCount('order_details', 0);
    }

    public function test_only_sales_or_admin_can_confirm_orders(): void
    {
        [$product] = $this->tomatoStock();
        $order = $this->createOrder([['product_id' => $product->id, 'quantity' => 1]]);
        $customer = User::factory()->create(['role_name' => 'Customer']);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/sales/orders/'.$order->id.'/confirm')
            ->assertForbidden();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseCount('order_details', 0);
    }

    public function test_checkout_rejects_invalid_products_and_quantities(): void
    {
        $product = $this->product('Tomato', 'TOM-01', 35000);
        $this->postJson('/api/v1/orders', $this->payload([
            ['product_id' => 9999, 'quantity' => 1],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['items.0.product_id']);

        $this->postJson('/api/v1/orders', $this->payload([
            ['product_id' => $product->id, 'quantity' => 0],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity']);

        $this->assertDatabaseCount('orders', 0);
    }

    private function tomatoStock(): array
    {
        $product = $this->product('Tomato', 'TOM-01', 35000);
        $first = $this->batch($product, 'TOM-A', now()->addDays(2)->toDateString(), 3, 3);
        $second = $this->batch($product, 'TOM-B', now()->addDays(5)->toDateString(), 7, 7);
        $expired = $this->batch($product, 'TOM-EXPIRED', now()->subDay()->toDateString(), 20, 20);
        $empty = $this->batch($product, 'TOM-EMPTY', now()->addDays(7)->toDateString(), 5, 0);

        return [$product, $first, $second, $expired, $empty];
    }

    private function product(string $name, string $sku, int $price): Product
    {
        return Product::create(['name' => $name, 'sku' => $sku, 'unit' => 'kg', 'price' => $price, 'is_active' => true]);
    }

    private function batch(Product $product, string $code, string $expiration, int $initial, int $available): Batch
    {
        return Batch::create(['product_id' => $product->id, 'batch_code' => $code, 'expiration_date' => $expiration, 'quantity_initial' => $initial, 'quantity_available' => $available]);
    }

    private function payload(array $items): array
    {
        return ['customer_name' => 'Test Customer', 'customer_email' => 'customer@example.com', 'customer_phone' => '0900000000', 'shipping_address' => '1 Farm Road', 'items' => $items];
    }

    private function createOrder(array $items): Order
    {
        $response = $this->postJson('/api/v1/orders', $this->payload($items));
        $response->assertCreated();

        return Order::findOrFail($response->json('data.id'));
    }
}
