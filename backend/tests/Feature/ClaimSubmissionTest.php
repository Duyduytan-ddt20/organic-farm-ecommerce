<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClaimSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::query()->create(['role_name' => 'Customer']);
        DB::table('categories')->insert([
            'id' => 1,
            'name' => 'Rau củ',
            'slug' => 'rau-cu',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('products')->insert([
            'id' => 1,
            'category_id' => 1,
            'name' => 'Cà chua',
            'slug' => 'ca-chua',
            'base_price' => 30000,
            'unit' => 'kg',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = User::factory()->create([
            'role_id' => $role->id,
            'password' => '12345678',
        ]);
    }

    public function test_customer_can_submit_a_claim_with_a_jpg_evidence_image_for_a_delivered_order(): void
    {
        Storage::fake('public');
        [$order, $item] = $this->createOrder($this->customer, 'delivered');

        $this->withToken($this->customer->createToken('test')->plainTextToken)
            ->post('/api/v1/customer/orders/'.$order->id.'/claims', [
                'order_item_id' => $item->id,
                'reason' => 'Rau củ bị hư hỏng',
                'description' => 'Một phần sản phẩm bị dập.',
                'claimed_quantity' => '0.50',
                'evidence' => UploadedFile::fake()->create('evidence.jpg', 20, 'image/jpeg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('claims', [
            'order_item_id' => $item->id,
            'claimed_quantity' => '0.50',
            'reason' => 'Rau củ bị hư hỏng',
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('claim_attachments', 1);
        $attachmentPath = DB::table('claim_attachments')->value('file_path');
        Storage::disk('public')->assertExists($attachmentPath);
    }

    public function test_customer_cannot_claim_an_undelivered_or_another_customers_order(): void
    {
        [$undeliveredOrder, $item] = $this->createOrder($this->customer, 'shipping');
        [$deliveredOrder] = $this->createOrder($this->customer, 'delivered');
        $otherCustomer = User::factory()->create([
            'role_id' => $this->customer->role_id,
            'password' => '12345678',
        ]);
        $token = $this->customer->createToken('test')->plainTextToken;
        $payload = [
            'order_item_id' => $item->id,
            'reason' => 'Sản phẩm không tươi',
            'claimed_quantity' => '1.00',
            'evidence' => UploadedFile::fake()->create('evidence.png', 20, 'image/png'),
        ];

        $this->withToken($token)
            ->post('/api/v1/customer/orders/'.$undeliveredOrder->id.'/claims', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order']);

        app('auth')->forgetGuards();
        $this->withToken($otherCustomer->createToken('test')->plainTextToken)
            ->post('/api/v1/customer/orders/'.$deliveredOrder->id.'/claims', $payload)
            ->assertNotFound();

        $this->assertDatabaseCount('claims', 0);
    }

    public function test_customer_must_upload_a_jpg_or_png_under_five_megabytes(): void
    {
        Storage::fake('public');
        [$order, $item] = $this->createOrder($this->customer, 'delivered');
        $token = $this->customer->createToken('test')->plainTextToken;
        $payload = [
            'order_item_id' => $item->id,
            'reason' => 'Sản phẩm không tươi',
            'claimed_quantity' => '1.00',
        ];

        $this->withToken($token)
            ->post('/api/v1/customer/orders/'.$order->id.'/claims', [
                ...$payload,
                'evidence' => UploadedFile::fake()->create('evidence.gif', 20, 'image/gif'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['evidence']);

        $this->withToken($token)
            ->post('/api/v1/customer/orders/'.$order->id.'/claims', [
                ...$payload,
                'evidence' => UploadedFile::fake()->create('large.png', 5120, 'image/png'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['evidence']);

        $this->assertDatabaseCount('claims', 0);
        Storage::disk('public')->assertDirectoryEmpty('claim-evidence');
    }

    /**
     * @return array{Order, \App\Models\OrderItem}
     */
    private function createOrder(User $customer, string $status): array
    {
        $order = Order::query()->create([
            'user_id' => $customer->id,
            'order_code' => 'ORD-'.uniqid(),
            'order_type' => 'retail',
            'recipient_name' => 'Khách hàng',
            'recipient_phone' => '0900000000',
            'delivery_address' => 'Địa chỉ thử nghiệm',
            'shipping_fee' => 0,
            'total_amount' => 30000,
            'status' => $status,
            'delivered_at' => $status === 'delivered' ? now() : null,
        ]);
        $item = $order->items()->create([
            'product_id' => 1,
            'quantity' => '1.00',
            'unit_price' => '30000.00',
        ]);

        return [$order, $item];
    }
}
