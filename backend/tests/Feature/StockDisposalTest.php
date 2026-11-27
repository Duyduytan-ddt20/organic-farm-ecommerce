<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StockDisposalTest extends TestCase
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

    public function test_staff_can_dispose_stock_and_staff_id_comes_from_token(): void
    {
        $batch = $this->batch('LO-DISPOSE', 'available', 10);
        $staff = $this->user('Staff');

        $this->withToken($staff->createToken('test')->plainTextToken)
            ->postJson('/api/v1/batches/'.$batch->id.'/disposals', [
                'quantity' => 3.5,
                'reason' => 'Hư hỏng',
                'note' => 'Kiểm tra',
                'disposed_at' => '2026-11-26 09:00:00',
                'staff_id' => 999,
            ])
            ->assertCreated()
            ->assertJsonPath('data.quantity', '3.50')
            ->assertJsonPath('data.available_quantity', '6.50')
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.image_path', null)
            ->assertJsonPath('data.staff.id', $staff->id);

        $this->assertDatabaseHas('stock_disposals', [
            'batch_id' => $batch->id,
            'staff_id' => $staff->id,
            'quantity' => '3.50',
        ]);
    }

    public function test_disposal_is_allowed_for_available_blocked_and_growing_but_not_closed(): void
    {
        foreach (['available', 'blocked', 'growing'] as $status) {
            $batch = $this->batch('LO-'.$status, $status, 5);
            $staff = $this->user('Staff');

            $this->withToken($staff->createToken('test')->plainTextToken)
                ->postJson('/api/v1/batches/'.$batch->id.'/disposals', [
                    'quantity' => 1,
                    'reason' => 'Hư hỏng',
                ])
                ->assertCreated()
                ->assertJsonPath('data.status', $status);
        }

        $closed = $this->batch('LO-CLOSED', 'closed', 5);
        $admin = $this->user('Admin');
        $this->withToken($admin->createToken('test')->plainTextToken)
            ->postJson('/api/v1/batches/'.$closed->id.'/disposals', [
                'quantity' => 1,
                'reason' => 'Hư hỏng',
            ])
            ->assertUnprocessable();
    }

    public function test_expired_batch_does_not_block_disposal(): void
    {
        $batch = $this->batch('LO-EXPIRED', 'available', 5);
        $batch->update(['expires_at' => '2020-01-01 00:00:00']);
        $staff = $this->user('Admin');

        $this->withToken($staff->createToken('test')->plainTextToken)
            ->postJson('/api/v1/batches/'.$batch->id.'/disposals', [
                'quantity' => 1,
                'reason' => 'Hết hạn',
            ])
            ->assertCreated();
    }

    public function test_zero_remaining_inventory_keeps_status_and_over_quantity_is_rejected(): void
    {
        $batch = $this->batch('LO-ZERO', 'blocked', 2);
        $staff = $this->user('Staff');

        $this->withToken($staff->createToken('test')->plainTextToken)
            ->postJson('/api/v1/batches/'.$batch->id.'/disposals', [
                'quantity' => 2,
                'reason' => 'Xuất hủy hết',
            ])
            ->assertCreated()
            ->assertJsonPath('data.available_quantity', '0.00')
            ->assertJsonPath('data.status', 'blocked');

        $batch = $this->batch('LO-OVER', 'available', 2);
        $this->withToken($staff->createToken('test')->plainTextToken)
            ->postJson('/api/v1/batches/'.$batch->id.'/disposals', [
                'quantity' => 2.01,
                'reason' => 'Vượt tồn',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_quantity_must_be_positive_and_customer_sales_guest_are_denied(): void
    {
        $batch = $this->batch('LO-PERMISSION', 'available', 5);

        $this->postJson('/api/v1/batches/'.$batch->id.'/disposals', [
            'quantity' => 1,
            'reason' => 'Hư hỏng',
        ])->assertUnauthorized();

        foreach (['Customer', 'Sales'] as $roleName) {
            $user = $this->user($roleName);
            app('auth')->forgetGuards();
            $this->withToken($user->createToken('test')->plainTextToken)
                ->postJson('/api/v1/batches/'.$batch->id.'/disposals', [
                    'quantity' => 1,
                    'reason' => 'Hư hỏng',
                ])
                ->assertForbidden();
        }

        $staff = $this->user('Staff');
        app('auth')->forgetGuards();
        $this->withToken($staff->createToken('test')->plainTextToken)
            ->postJson('/api/v1/batches/'.$batch->id.'/disposals', [
                'quantity' => 0,
                'reason' => 'Không hợp lệ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_disposal_accepts_image_and_available_stock_can_be_closed(): void
    {
        Storage::fake('public');
        $batch = $this->batch('LO-FULL-DISPOSE', 'available', 2);
        $staff = $this->user('Staff');
        $token = $staff->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->post('/api/v1/batches/'.$batch->id.'/disposals', [
                'quantity' => 2,
                'reason' => 'Xuất hủy hết',
                'image' => UploadedFile::fake()->createWithContent(
                    'xuat-huy.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
                ),
            ])
            ->assertCreated()
            ->assertJsonPath('data.available_quantity', '0.00')
            ->assertJsonPath('data.status', 'closed');

        $this->assertNotNull($batch->stockDisposals()->first()->image_path);
        $this->withToken($token)
            ->getJson('/api/v1/batches/'.$batch->id.'/disposals')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    private function batch(string $code, string $status, int $available): Batch
    {
        return Batch::query()->create([
            'product_id' => 1,
            'batch_code' => $code,
            'growing_location' => 'Nhà màng 1',
            'harvested_at' => '2026-11-22',
            'expires_at' => '2026-12-01 08:00:00',
            'received_quantity' => 20,
            'available_quantity' => $available,
            'status' => $status,
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
