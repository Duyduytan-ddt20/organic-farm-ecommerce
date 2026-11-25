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

class FarmingLogTest extends TestCase
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

    public function test_staff_and_admin_can_create_all_supported_activities(): void
    {
        foreach (['Gieo trồng', 'Chăm sóc', 'Thu hoạch'] as $index => $activity) {
            $batch = $this->batch('LO-'.$index);
            $roleName = $index === 2 ? 'Admin' : 'Staff';
            $staff = $this->user($roleName);

            app('auth')->forgetGuards();
            $this->withToken($staff->createToken('test')->plainTextToken)
                ->postJson('/api/v1/batches/'.$batch->id.'/farming-logs', [
                    'activity' => $activity,
                    'description' => 'Ghi chú',
                    'performed_at' => '2026-11-26 08:00:00',
                    'staff_id' => 999,
                ])
                ->assertCreated()
                ->assertJsonPath('data.activity', $activity)
                ->assertJsonPath('data.image_path', null)
                ->assertJsonPath('data.staff.id', $staff->id);

            $batch->refresh();
            $this->assertEquals('10.00', number_format((float) $batch->available_quantity, 2, '.', ''));
        }
    }

    public function test_invalid_activity_returns_validation_error(): void
    {
        $batch = $this->batch('LO-INVALID');
        $staff = $this->user('Staff');

        $this->withToken($staff->createToken('test')->plainTextToken)
            ->postJson('/api/v1/batches/'.$batch->id.'/farming-logs', [
                'activity' => 'Không hợp lệ',
                'performed_at' => '2026-11-26 08:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['activity']);
    }

    public function test_customer_sales_and_guest_cannot_create_log(): void
    {
        $batch = $this->batch('LO-PERMISSION');

        $this->postJson('/api/v1/batches/'.$batch->id.'/farming-logs', [
            'activity' => 'Chăm sóc',
            'performed_at' => '2026-11-26 08:00:00',
        ])->assertUnauthorized();

        foreach (['Customer', 'Sales'] as $roleName) {
            $user = $this->user($roleName);
            app('auth')->forgetGuards();
            $this->withToken($user->createToken('test')->plainTextToken)
                ->postJson('/api/v1/batches/'.$batch->id.'/farming-logs', [
                    'activity' => 'Chăm sóc',
                    'performed_at' => '2026-11-26 08:00:00',
                ])
                ->assertForbidden();
        }
    }

    public function test_farming_log_accepts_image_and_history_can_be_listed(): void
    {
        Storage::fake('public');
        $batch = $this->batch('LO-IMAGE');
        $staff = $this->user('Staff');
        $token = $staff->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->post('/api/v1/batches/'.$batch->id.'/farming-logs', [
                'activity' => 'Chăm sóc',
                'description' => 'Phun hữu cơ',
                'performed_at' => '2026-11-26 08:00:00',
                'image' => UploadedFile::fake()->createWithContent(
                    'canh-tac.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
                ),
            ])
            ->assertCreated();

        $this->assertNotNull($batch->farmingLogs()->first()->image_path);
        $this->withToken($token)
            ->getJson('/api/v1/batches/'.$batch->id.'/farming-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    private function batch(string $code): Batch
    {
        return Batch::query()->create([
            'product_id' => 1,
            'batch_code' => $code,
            'growing_location' => 'Nhà màng 1',
            'harvested_at' => '2026-11-22',
            'expires_at' => '2026-12-01 08:00:00',
            'received_quantity' => 20,
            'available_quantity' => 10,
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
