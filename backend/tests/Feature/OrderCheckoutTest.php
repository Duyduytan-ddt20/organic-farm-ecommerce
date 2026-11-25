<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('roles')->insert([
            ['id' => 1, 'role_name' => 'Customer', 'description' => 'Customer test role'],
            ['id' => 2, 'role_name' => 'Sales', 'description' => 'Sales test role'],
            ['id' => 3, 'role_name' => 'Admin', 'description' => 'Admin test role'],
        ]);
        $this->category = Category::create(['name' => 'Test vegetables', 'slug' => 'test-vegetables', 'is_active' => true]);
        $this->customer = User::factory()->create(['role_id' => 1]);
    }

    public function test_checkout_creates_pending_order_items_without_decrementing_stock(): void
    {
        [$product, $firstBatch, $secondBatch] = $this->tomatoStock();

        $response = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', $this->payload([
            ['product_id' => $product->id, 'quantity' => 10],
        ]));

        $response->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.items.0.quantity', '10.00');
        $this->assertDatabaseHas('order_items', ['order_id' => $response->json('data.id'), 'product_id' => $product->id, 'quantity' => 10]);
        $this->assertDatabaseMissing('order_details', ['order_item_id' => $response->json('data.items.0.id')]);
        $this->assertSame('3.00', $firstBatch->fresh()->available_quantity);
        $this->assertSame('7.00', $secondBatch->fresh()->available_quantity);
    }

    public function test_sales_confirmation_allocates_by_fefo_and_decrements_each_batch(): void
    {
        [$product, $firstBatch, $secondBatch, $expiredBatch, $emptyBatch] = $this->tomatoStock();
        $order = $this->createOrder([['product_id' => $product->id, 'quantity' => 10]]);
        $salesUser = User::factory()->create(['role_id' => 2]);
        $confirmUrl = '/api/v1/sales/orders/'.$order->id.'/confirm';

        $this->actingAs($salesUser, 'sanctum')->postJson($confirmUrl)
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.items.0.allocations.0.batch_id', $firstBatch->id)
            ->assertJsonPath('data.items.0.allocations.0.quantity', '3.00')
            ->assertJsonPath('data.items.0.allocations.1.batch_id', $secondBatch->id)
            ->assertJsonPath('data.items.0.allocations.1.quantity', '7.00');

        $this->assertSame('0.00', $firstBatch->fresh()->available_quantity);
        $this->assertSame('0.00', $secondBatch->fresh()->available_quantity);
        $this->assertSame('20.00', $expiredBatch->fresh()->available_quantity);
        $this->assertSame('0.00', $emptyBatch->fresh()->available_quantity);
        $this->assertDatabaseCount('order_details', 2);
        $this->postJson($confirmUrl)->assertOk();
        $this->assertDatabaseCount('order_details', 2);
    }

    public function test_checkout_rejects_insufficient_valid_stock_without_creating_an_order(): void
    {
        [$product] = $this->tomatoStock();

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', $this->payload([
            ['product_id' => $product->id, 'quantity' => 11],
        ]))->assertStatus(409)->assertJsonPath('status', 'error')->assertJsonPath('requested', 11)->assertJsonPath('available', 10);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_confirmation_rolls_back_all_allocations_when_a_later_item_lacks_stock(): void
    {
        [$tomato, $tomatoFirst, $tomatoSecond] = $this->tomatoStock();
        $cucumber = $this->product('Cucumber', 'cucumber');
        $cucumberBatch = $this->batch($cucumber, 'CUC-BATCH-1', now()->addDays(4), 2, 2);
        $order = $this->createOrder([
            ['product_id' => $tomato->id, 'quantity' => 4],
            ['product_id' => $cucumber->id, 'quantity' => 2],
        ]);
        $cucumberBatch->update(['available_quantity' => 1]);
        $salesUser = User::factory()->create(['role_id' => 2]);

        $this->actingAs($salesUser, 'sanctum')->postJson('/api/v1/sales/orders/'.$order->id.'/confirm')
            ->assertStatus(409)->assertJsonPath('status', 'error');

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('3.00', $tomatoFirst->fresh()->available_quantity);
        $this->assertSame('7.00', $tomatoSecond->fresh()->available_quantity);
        $this->assertSame('1.00', $cucumberBatch->fresh()->available_quantity);
        $this->assertDatabaseCount('order_details', 0);
    }

    public function test_only_sales_or_admin_can_confirm_orders(): void
    {
        [$product] = $this->tomatoStock();
        $order = $this->createOrder([['product_id' => $product->id, 'quantity' => 1]]);

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/sales/orders/'.$order->id.'/confirm')->assertForbidden();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseCount('order_details', 0);
    }

    public function test_checkout_rejects_invalid_products_and_quantities(): void
    {
        $product = $this->product('Tomato', 'tomato');
        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', $this->payload([
            ['product_id' => 9999, 'quantity' => 1],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['items.0.product_id']);

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', $this->payload([
            ['product_id' => $product->id, 'quantity' => 0],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity']);

        $this->assertDatabaseCount('orders', 0);
    }

    private function tomatoStock(): array
    {
        $product = $this->product('Tomato', 'tomato');
        $first = $this->batch($product, 'TOM-A', now()->addDays(2), 3, 3);
        $second = $this->batch($product, 'TOM-B', now()->addDays(5), 7, 7);
        $expired = $this->batch($product, 'TOM-EXPIRED', now()->subDay(), 20, 20);
        $empty = $this->batch($product, 'TOM-EMPTY', now()->addDays(7), 5, 0);

        return [$product, $first, $second, $expired, $empty];
    }

    private function product(string $name, string $slug): Product
    {
        return Product::create(['category_id' => $this->category->id, 'name' => $name, 'slug' => $slug, 'base_price' => 35000, 'unit' => 'kg', 'is_active' => true]);
    }

    private function batch(Product $product, string $code, $expiresAt, int $received, int $available): Batch
    {
        return Batch::create(['product_id' => $product->id, 'batch_code' => $code, 'growing_location' => 'Test greenhouse', 'expires_at' => $expiresAt, 'received_quantity' => $received, 'available_quantity' => $available, 'status' => 'available']);
    }

    private function payload(array $items): array
    {
        return ['recipient_name' => 'Test Customer', 'recipient_phone' => '0900000000', 'delivery_address' => '1 Farm Road', 'items' => $items];
    }

    private function createOrder(array $items): Order
    {
        $response = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', $this->payload($items));
        $response->assertCreated();
        return Order::findOrFail($response->json('data.id'));
    }
}
