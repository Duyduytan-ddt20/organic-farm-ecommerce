<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            ['role_name' => 'Customer', 'description' => 'Khách hàng mua sắm'],
            ['role_name' => 'Staff', 'description' => 'Nhân viên nông trại'],
            ['role_name' => 'Sales', 'description' => 'Nhân viên bán hàng và CSKH'],
            ['role_name' => 'Admin', 'description' => 'Quản trị viên hệ thống'],
        ] as $role) {
            Role::query()->create($role);
        }
    }

    public function test_customer_can_register_and_receive_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'full_name' => 'Nguyễn Văn A',
            'email' => 'vana@organicfarm.vn',
            'phone' => '0901888999',
            'password' => '12345678',
            'password_confirmation' => '12345678',
            'role_name' => 'Admin',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'vana@organicfarm.vn')
            ->assertJsonPath('data.user.role.role_name', 'Customer');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('users', [
            'email' => 'vana@organicfarm.vn',
            'role_id' => Role::query()->where('role_name', 'Customer')->value('id'),
        ]);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload('dup@organicfarm.vn'))
            ->assertCreated();

        $this->postJson('/api/v1/auth/register', $this->registerPayload('dup@organicfarm.vn'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_login_logout_and_read_profile(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user('Staff')->email,
            'password' => '12345678',
        ]);

        $login->assertOk()
            ->assertJsonPath('data.user.role.role_name', 'Staff');

        $token = $login->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role.role_name', 'Staff');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_login_rejects_wrong_password_and_inactive_account(): void
    {
        $user = $this->user('Customer');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'sai-mat-khau',
        ])->assertUnauthorized();

        $user->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => '12345678',
        ])->assertForbidden();
    }

    public function test_four_roles_are_enforced(): void
    {
        $customer = $this->tokenFor('Customer');
        $staff = $this->tokenFor('Staff');
        $sales = $this->tokenFor('Sales');
        $admin = $this->tokenFor('Admin');

        $this->getAs($customer, '/api/v1/customer/dashboard')->assertOk();
        $this->getAs($customer, '/api/v1/staff/dashboard')->assertForbidden();
        $this->getAs($customer, '/api/v1/sales/dashboard')->assertForbidden();
        $this->getAs($customer, '/api/v1/admin/dashboard')->assertForbidden();

        $this->getAs($staff, '/api/v1/staff/dashboard')->assertOk();
        $this->getAs($staff, '/api/v1/sales/dashboard')->assertForbidden();
        $this->getAs($staff, '/api/v1/admin/dashboard')->assertForbidden();

        $this->getAs($sales, '/api/v1/sales/dashboard')->assertOk();
        $this->getAs($sales, '/api/v1/staff/dashboard')->assertForbidden();

        $this->getAs($admin, '/api/v1/admin/dashboard')->assertOk();
        $this->getAs($admin, '/api/v1/staff/dashboard')->assertOk();
        $this->getAs($admin, '/api/v1/sales/dashboard')->assertOk();
        $this->getAs($admin, '/api/v1/customer/dashboard')->assertOk();
    }

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    private function user(string $roleName): User
    {
        $role = Role::query()->where('role_name', $roleName)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'password' => '12345678',
        ]);
    }

    private function tokenFor(string $roleName): string
    {
        return $this->user($roleName)->createToken('test')->plainTextToken;
    }

    private function getAs(string $token, string $uri)
    {
        app('auth')->forgetGuards();

        return $this->withToken($token)->getJson($uri);
    }

    /**
     * @return array<string, string>
     */
    private function registerPayload(string $email): array
    {
        return [
            'full_name' => 'Khách mới',
            'email' => $email,
            'phone' => '0901000111',
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ];
    }
}
