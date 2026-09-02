<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function test_login_with_valid_credentials_returns_token(): void
    {
        $user = User::create([
            'role_id' => \App\Models\Role::where('name', 'security')->first()->id,
            'employee_code' => 'SEC-X1',
            'name' => 'Budi',
            'username' => 'budi_login',
            'password' => 'secret123',
            'phone' => '0812',
            'status' => 'ACTIVE',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'budi_login',
            'password' => 'secret123',
            'device_uuid' => 'DEV-LOGIN-1',
            'device_name' => 'Samsung A54',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['token', 'user' => ['id', 'name', 'role']],
            ]);

        $this->assertDatabaseHas('devices', ['device_uuid' => 'DEV-LOGIN-1', 'user_id' => $user->id]);
    }

    public function test_login_wrong_password_fails(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'username' => 'nonexistent',
            'password' => 'wrongpass',
        ])->assertStatus(422);
    }

    public function test_security_login_requires_device_uuid(): void
    {
        User::create([
            'role_id' => \App\Models\Role::where('name', 'security')->first()->id,
            'employee_code' => 'SEC-X2',
            'name' => 'Andi',
            'username' => 'andi_login',
            'password' => 'secret123',
            'status' => 'ACTIVE',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'andi_login',
            'password' => 'secret123',
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'DEVICE_REQUIRED');
    }

    public function test_me_returns_current_user(): void
    {
        $user = $this->createUser(RoleName::SECURITY);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertStatus(200)
            ->assertJsonPath('data.username', $user->username);
    }

    public function test_unauthenticated_request_rejected(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }
}
