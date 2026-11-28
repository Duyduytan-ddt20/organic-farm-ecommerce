<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\FarmingLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BatchSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QrTraceTest extends TestCase
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
            'description' => 'Mô tả',
            'base_price' => 38000,
            'unit' => 'kg',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_staff_receives_svg_and_trace_is_public(): void
    {
        $batch = $this->batch('LO-001', 'token-one');
        $staff = $this->user('Staff');

        $this->withToken($staff->createToken('test')->plainTextToken)
            ->get('/api/v1/batches/'.$batch->id.'/qr')
            ->assertOk()
            ->assertHeader('content-type', 'image/svg+xml')
            ->assertSee('/truy-xuat/token-one', false);

        $this->getJson('/api/v1/trace/token-one')
            ->assertOk()
            ->assertJsonPath('data.product.name', 'Cà chua hữu cơ')
            ->assertJsonPath('data.batch.batch_code', 'LO-001')
            ->assertJsonPath('data.responsible_staff', null)
            ->assertJsonMissingPath('data.qr_token')
            ->assertJsonMissingPath('data.batch.available_quantity')
            ->assertJsonMissingPath('data.product.base_price');
    }

    public function test_three_batches_have_distinct_qr_tokens_and_trace_correct_batch(): void
    {
        $first = $this->batch('LO-A', 'token-a');
        $second = $this->batch('LO-B', 'token-b');
        $third = $this->batch('LO-C', 'token-c');

        $this->assertNotEquals($first->qr_token, $second->qr_token);
        $this->assertNotEquals($second->qr_token, $third->qr_token);

        $this->getJson('/api/v1/trace/token-b')
            ->assertOk()
            ->assertJsonPath('data.batch.batch_code', 'LO-B');
    }

    public function test_responsible_staff_is_from_latest_performed_log(): void
    {
        $batch = $this->batch('LO-STAFF', 'token-staff');
        $firstStaff = $this->user('Staff', 'Nhân viên A');
        $latestStaff = $this->user('Staff', 'Nhân viên B');

        FarmingLog::query()->create([
            'batch_id' => $batch->id,
            'staff_id' => $firstStaff->id,
            'activity' => 'Gieo trồng',
            'performed_at' => '2026-11-20 08:00:00',
            'image_path' => null,
        ]);
        FarmingLog::query()->create([
            'batch_id' => $batch->id,
            'staff_id' => $latestStaff->id,
            'activity' => 'Thu hoạch',
            'performed_at' => '2026-11-22 08:00:00',
            'image_path' => null,
        ]);

        $this->getJson('/api/v1/trace/token-staff')
            ->assertOk()
            ->assertJsonPath('data.responsible_staff.id', $latestStaff->id)
            ->assertJsonPath('data.responsible_staff.full_name', 'Nhân viên B')
            ->assertJsonCount(2, 'data.farming_logs');
    }

    public function test_sample_batch_seeder_adds_idempotent_farming_logs_and_trace_data(): void
    {
        $this->user('Staff', 'Nhân viên An');
        $existingBatch = $this->batch('LO-EXISTING', 'token-existing');
        $seeder = app(BatchSampleSeeder::class);

        $seeder->run();
        $seeder->run();

        $batch = Batch::query()->where('batch_code', 'SAMPLE-P1-A')->firstOrFail();

        $this->assertSame(3, $batch->farmingLogs()->count());
        $this->assertSame(3, $existingBatch->farmingLogs()->count());
        $this->getJson('/api/v1/trace/'.$batch->qr_token)
            ->assertOk()
            ->assertJsonPath('data.responsible_staff.full_name', 'Nhân viên An')
            ->assertJsonCount(3, 'data.farming_logs');
        $this->getJson('/api/v1/trace/'.$existingBatch->qr_token)
            ->assertOk()
            ->assertJsonPath('data.responsible_staff.full_name', 'Nhân viên An')
            ->assertJsonCount(3, 'data.farming_logs');
    }

    public function test_missing_token_returns_not_found_and_batch_without_token_returns_unprocessable(): void
    {
        $batch = $this->batch('LO-NO-TOKEN', null);

        $this->getJson('/api/v1/trace/token-missing')->assertNotFound();

        $staff = $this->user('Staff');
        $this->withToken($staff->createToken('test')->plainTextToken)
            ->getJson('/api/v1/batches/'.$batch->id.'/qr')
            ->assertUnprocessable();
    }

    public function test_creating_batch_generates_unique_qr_token(): void
    {
        $this->postJson('/api/v1/batches', [
            'product_id' => 1,
            'batch_code' => 'LO-AUTO',
            'growing_location' => 'Nhà màng',
            'harvested_at' => '2026-11-22',
            'expires_at' => '2026-12-01 08:00:00',
            'received_quantity' => 20,
        ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'batch_code', 'qr_token']]);

        $batch = Batch::where('batch_code', 'LO-AUTO')->firstOrFail();
        $this->assertNotEmpty($batch->qr_token);
        $this->assertSame(32, strlen($batch->qr_token));
    }

    public function test_customer_sales_and_guest_cannot_get_qr(): void
    {
        $batch = $this->batch('LO-AUTH', 'token-auth');

        $this->getJson('/api/v1/batches/'.$batch->id.'/qr')->assertUnauthorized();

        foreach (['Customer', 'Sales'] as $roleName) {
            $user = $this->user($roleName);
            $this->withToken($user->createToken('test')->plainTextToken)
                ->getJson('/api/v1/batches/'.$batch->id.'/qr')
                ->assertForbidden();
        }
    }

    private function batch(string $code, ?string $token): Batch
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
            'qr_token' => $token,
        ]);
    }

    private function user(string $roleName, ?string $name = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('role_name', $roleName)->value('id'),
            'full_name' => $name ?? 'Người dùng '.$roleName,
            'password' => '12345678',
        ]);
    }
}
