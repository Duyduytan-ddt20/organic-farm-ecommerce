<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClaimUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('roles')->insert([
            ['id' => 1, 'role_name' => 'Customer', 'description' => 'Customer test role'],
            ['id' => 2, 'role_name' => 'Sales', 'description' => 'Sales test role'],
            ['id' => 3, 'role_name' => 'Admin', 'description' => 'Admin test role'],
        ]);
        $category = Category::create(['name' => 'Test vegetables', 'slug' => 'test-vegetables', 'is_active' => true]);
        $this->customer = User::factory()->create(['role_id' => 1]);
        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Tomato',
            'slug' => 'tomato',
            'base_price' => 35000,
            'unit' => 'kg',
            'is_active' => true,
        ]);
    }

    public function test_customer_stores_claim_image_for_a_delivered_order(): void
    {
        Storage::fake('public');
        $order = $this->deliveredOrder();
        $item = $order->items()->first();
        $image = $this->fakeImage('hu-hong.png');

        $response = $this->actingAs($this->customer, 'sanctum')->post('/api/v1/orders/'.$order->id.'/claims', [
            'order_item_id' => $item->id,
            'reason' => 'Hư hỏng khi nhận hàng',
            'description' => 'Cà chua bị dập.',
            'claimed_quantity' => 1,
            'images' => [$image],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.order_item_id', $item->id)
            ->assertJsonPath('data.attachments.0.original_name', 'hu-hong.png')
            ->assertJsonPath('data.attachments.0.mime_type', 'image/png');

        $path = $response->json('data.attachments.0.file_path');
        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('claims', [
            'id' => $response->json('data.id'),
            'order_item_id' => $item->id,
            'reason' => 'Hư hỏng khi nhận hàng',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('claim_attachments', [
            'claim_id' => $response->json('data.id'),
            'file_path' => $path,
            'original_name' => 'hu-hong.png',
        ]);
    }

    public function test_customer_can_delete_a_pending_claim_attachment_from_storage(): void
    {
        Storage::fake('public');
        $order = $this->deliveredOrder();
        $item = $order->items()->first();
        $created = $this->actingAs($this->customer, 'sanctum')->post('/api/v1/orders/'.$order->id.'/claims', [
            'order_item_id' => $item->id,
            'reason' => 'Hư hỏng khi nhận hàng',
            'claimed_quantity' => 1,
            'images' => [$this->fakeImage('remove-me.png')],
        ])->assertCreated();

        $claimId = $created->json('data.id');
        $attachmentId = $created->json('data.attachments.0.id');
        $path = $created->json('data.attachments.0.file_path');
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->customer, 'sanctum')
            ->deleteJson('/api/v1/claims/'.$claimId.'/attachments/'.$attachmentId)
            ->assertOk()
            ->assertJsonPath('status', 'success');

        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseMissing('claim_attachments', ['id' => $attachmentId]);
        $this->assertDatabaseHas('claims', ['id' => $claimId, 'status' => 'pending']);
    }

    public function test_customer_can_edit_a_pending_claim_and_add_an_image(): void
    {
        Storage::fake('public');
        $order = $this->deliveredOrder();
        $item = $order->items()->first();
        $created = $this->actingAs($this->customer, 'sanctum')->post('/api/v1/orders/'.$order->id.'/claims', [
            'order_item_id' => $item->id,
            'reason' => 'Hư hỏng khi nhận hàng',
            'description' => 'Mô tả cũ',
            'claimed_quantity' => 1,
            'images' => [$this->fakeImage('original.png')],
        ])->assertCreated();
        $claimId = $created->json('data.id');
        $originalPath = $created->json('data.attachments.0.file_path');

        $updated = $this->actingAs($this->customer, 'sanctum')->put('/api/v1/claims/'.$claimId, [
            'reason' => 'Thiếu số lượng',
            'description' => 'Đã cập nhật mô tả',
            'claimed_quantity' => 1.5,
            'images' => [$this->fakeImage('additional.png')],
        ]);

        $updated->assertOk()
            ->assertJsonPath('data.reason', 'Thiếu số lượng')
            ->assertJsonPath('data.description', 'Đã cập nhật mô tả');
        $this->assertSame(1.5, (float) $updated->json('data.claimed_quantity'));
        $this->assertCount(2, $updated->json('data.attachments'));
        $this->assertDatabaseHas('claims', [
            'id' => $claimId,
            'reason' => 'Thiếu số lượng',
            'description' => 'Đã cập nhật mô tả',
            'claimed_quantity' => 1.5,
            'status' => 'pending',
        ]);
        Storage::disk('public')->assertExists($originalPath);
        Storage::disk('public')->assertExists($updated->json('data.attachments.1.file_path'));
    }

    public function test_editing_a_claim_cannot_exceed_five_total_images(): void
    {
        Storage::fake('public');
        $order = $this->deliveredOrder();
        $created = $this->actingAs($this->customer, 'sanctum')->post('/api/v1/orders/'.$order->id.'/claims', [
            'order_item_id' => $order->items()->first()->id,
            'reason' => 'Hư hỏng khi nhận hàng',
            'claimed_quantity' => 1,
            'images' => [
                $this->fakeImage('one.png'),
                $this->fakeImage('two.png'),
                $this->fakeImage('three.png'),
                $this->fakeImage('four.png'),
                $this->fakeImage('five.png'),
            ],
        ])->assertCreated();
        $claimId = $created->json('data.id');

        $this->actingAs($this->customer, 'sanctum')
            ->put('/api/v1/claims/'.$claimId, [
                'reason' => 'Thiếu số lượng',
                'claimed_quantity' => 1,
                'images' => [$this->fakeImage('six.png')],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['images']);

        $this->assertDatabaseHas('claims', ['id' => $claimId, 'reason' => 'Hư hỏng khi nhận hàng']);
        $this->assertDatabaseCount('claim_attachments', 5);
        $this->assertCount(5, Storage::disk('public')->allFiles());
    }

    public function test_only_the_order_owner_can_edit_a_pending_claim(): void
    {
        Storage::fake('public');
        $order = $this->deliveredOrder();
        $created = $this->actingAs($this->customer, 'sanctum')->post('/api/v1/orders/'.$order->id.'/claims', [
            'order_item_id' => $order->items()->first()->id,
            'reason' => 'Hư hỏng khi nhận hàng',
            'claimed_quantity' => 1,
            'images' => [$this->fakeImage('protected.png')],
        ])->assertCreated();
        $claimId = $created->json('data.id');
        $otherCustomer = User::factory()->create(['role_id' => 1]);
        $payload = [
            'reason' => 'Thiếu số lượng',
            'description' => 'Không thuộc tài khoản này',
            'claimed_quantity' => 1,
        ];

        $this->actingAs($otherCustomer, 'sanctum')
            ->putJson('/api/v1/claims/'.$claimId, $payload)
            ->assertForbidden();

        DB::table('claims')->where('id', $claimId)->update(['status' => 'approved']);
        $this->actingAs($this->customer, 'sanctum')
            ->putJson('/api/v1/claims/'.$claimId, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['claim']);
        $this->assertDatabaseHas('claims', [
            'id' => $claimId,
            'reason' => 'Hư hỏng khi nhận hàng',
            'status' => 'approved',
        ]);
    }

    public function test_only_the_order_owner_can_delete_pending_claim_attachments(): void
    {
        Storage::fake('public');
        $order = $this->deliveredOrder();
        $created = $this->actingAs($this->customer, 'sanctum')->post('/api/v1/orders/'.$order->id.'/claims', [
            'order_item_id' => $order->items()->first()->id,
            'reason' => 'Hư hỏng khi nhận hàng',
            'claimed_quantity' => 1,
            'images' => [$this->fakeImage('protected.png')],
        ])->assertCreated();
        $claimId = $created->json('data.id');
        $attachmentId = $created->json('data.attachments.0.id');
        $path = $created->json('data.attachments.0.file_path');
        $otherCustomer = User::factory()->create(['role_id' => 1]);

        $this->actingAs($otherCustomer, 'sanctum')
            ->deleteJson('/api/v1/claims/'.$claimId.'/attachments/'.$attachmentId)
            ->assertForbidden();
        Storage::disk('public')->assertExists($path);

        DB::table('claims')->where('id', $claimId)->update(['status' => 'approved']);
        $this->actingAs($this->customer, 'sanctum')
            ->deleteJson('/api/v1/claims/'.$claimId.'/attachments/'.$attachmentId)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['claim']);
        Storage::disk('public')->assertExists($path);
    }

    public function test_customer_can_login_and_submit_a_claim_with_the_returned_token(): void
    {
        Storage::fake('public');
        $this->customer->update(['password' => 'password123']);
        $order = $this->deliveredOrder();
        $item = $order->items()->first();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $this->customer->email,
            'password' => 'password123',
        ])->assertOk();

        $response = $this->withToken($login->json('data.token'))->post('/api/v1/orders/'.$order->id.'/claims', [
            'order_item_id' => $item->id,
            'reason' => 'Hư hỏng khi nhận hàng',
            'claimed_quantity' => 1,
            'images' => [$this->fakeImage('hu-hong.png')],
        ]);

        $response->assertCreated();
        Storage::disk('public')->assertExists($response->json('data.attachments.0.file_path'));
    }

    public function test_customer_can_open_the_seeded_delivered_order_preview(): void
    {
        $order = $this->deliveredOrder(['order_code' => 'ORD-DEMO-DELIVERED']);

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/orders/preview')
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'delivered');
    }

    public function test_claim_is_rejected_unless_the_order_is_delivered(): void
    {
        Storage::fake('public');
        $order = $this->deliveredOrder(['status' => 'pending', 'delivered_at' => null]);
        $item = $order->items()->first();

        $this->actingAs($this->customer, 'sanctum')->post('/api/v1/orders/'.$order->id.'/claims', [
            'order_item_id' => $item->id,
            'reason' => 'Hư hỏng khi nhận hàng',
            'claimed_quantity' => 1,
            'images' => [$this->fakeImage('hu-hong.png')],
        ])->assertUnprocessable()->assertJsonValidationErrors(['order']);

        $this->assertDatabaseCount('claims', 0);
        $this->assertDatabaseCount('claim_attachments', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_invalid_format_and_oversized_images_are_rejected(): void
    {
        Storage::fake('public');
        $order = $this->deliveredOrder();
        $item = $order->items()->first();
        $payload = [
            'order_item_id' => $item->id,
            'reason' => 'Hư hỏng khi nhận hàng',
            'claimed_quantity' => 1,
        ];

        $this->actingAs($this->customer, 'sanctum')->post('/api/v1/orders/'.$order->id.'/claims', [
            ...$payload,
            'images' => [UploadedFile::fake()->create('note.pdf', 20, 'application/pdf')],
        ])->assertUnprocessable()->assertJsonValidationErrors(['images.0']);

        $this->actingAs($this->customer, 'sanctum')->post('/api/v1/orders/'.$order->id.'/claims', [
            ...$payload,
            'images' => [$this->fakeImage('qua-nang.png')->size(5120)],
        ])->assertUnprocessable()->assertJsonValidationErrors(['images.0']);

        $this->assertDatabaseCount('claims', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_another_customer_cannot_open_or_claim_the_order(): void
    {
        $order = $this->deliveredOrder();
        $other = User::factory()->create(['role_id' => 1]);

        $this->actingAs($other, 'sanctum')->getJson('/api/v1/orders/'.$order->id)->assertForbidden();
        $this->actingAs($other, 'sanctum')->post('/api/v1/orders/'.$order->id.'/claims', [
            'order_item_id' => $order->items()->first()->id,
            'reason' => 'Hư hỏng khi nhận hàng',
            'claimed_quantity' => 1,
            'images' => [$this->fakeImage('hu-hong.png')],
        ])->assertForbidden();

        $this->assertDatabaseCount('claims', 0);
    }

    private function deliveredOrder(array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $this->customer->id,
            'order_code' => 'ORD-CLAIM-'.fake()->unique()->numerify('####'),
            'order_type' => 'retail',
            'recipient_name' => 'Test Customer',
            'recipient_phone' => '0900000000',
            'delivery_address' => '1 Farm Road',
            'shipping_fee' => 0,
            'total_amount' => 35000,
            'status' => 'delivered',
            'delivered_at' => now(),
        ], $overrides));

        $order->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 35000,
        ]);

        return $order->load('items');
    }

    private function fakeImage(string $filename): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $filename,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
        );
    }
}
