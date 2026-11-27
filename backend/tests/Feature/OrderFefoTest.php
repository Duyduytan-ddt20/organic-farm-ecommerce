<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderFefoTest extends TestCase
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
    }

    public function test_customer_can_checkout_and_sales_confirm_allocates_fefo(): void
    {
        $first = $this->batch('CC-A01', 3, '2026-12-01');
        $second = $this->batch('CC-B02', 25, '2026-12-10');
        $customer = $this->user('Customer');
        $sales = $this->user('Sales');

        $create = $this->withToken($customer->createToken('test')->plainTextToken)
            ->postJson('/api/v1/customer/orders', [
                'recipient_name' => 'Khách Hàng',
                'recipient_phone' => '0900000000',
                'delivery_address' => 'Hà Nội',
                'shipping_fee' => 30000,
                'items' => [['product_id' => 1, 'quantity' => 5]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $orderId = $create->json('data.id');

        app('auth')->forgetGuards();
        $this->withToken($sales->createToken('test')->plainTextToken)
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

    public function test_checkout_rejects_quantity_without_enough_available_stock(): void
    {
        $this->batch('CC-SMALL', 2, '2026-12-10');
        $customer = $this->user('Customer');

        $this->withToken($customer->createToken('test')->plainTextToken)
            ->postJson('/api/v1/customer/orders', [
                'recipient_name' => 'Khách Hàng',
                'recipient_phone' => '0900000000',
                'delivery_address' => 'Hà Nội',
                'items' => [['product_id' => 1, 'quantity' => 3]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
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
