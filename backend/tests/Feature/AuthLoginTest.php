<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['role_name' => 'Customer', 'description' => 'Customer test role']);
    }

    public function test_customer_can_login_and_receive_a_sanctum_token(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role.role_name', 'Customer');
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'web',
        ]);
    }

    public function test_customer_can_register_and_receive_a_token(): void
    {
        $adminRole = Role::create(['role_name' => 'Admin', 'description' => 'Admin test role']);
        $response = $this->postJson('/api/v1/auth/register', [
            'full_name' => 'Nguyen Thi B',
            'email' => 'new-customer@example.com',
            'phone' => '0901234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_id' => $adminRole->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.full_name', 'Nguyen Thi B')
            ->assertJsonPath('data.user.role.role_name', 'Customer')
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseHas('users', [
            'email' => 'new-customer@example.com',
            'role_id' => Role::query()->where('role_name', 'Customer')->value('id'),
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $response->json('data.user.id'),
            'name' => 'web',
        ]);
    }

    public function test_registration_rejects_duplicate_email_and_mismatched_password(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'full_name' => 'Another Customer',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different123',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'incorrect',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'password123',
        ]);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'password123',
        ])->json('data.token');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }
}
