<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function test_super_admin_can_create_area(): void
    {
        $admin = $this->createUser(RoleName::SUPER_ADMIN);

        $this->actingAs($admin)->postJson('/api/v1/admin/areas', [
            'name' => 'Cluster Anggrek',
            'description' => 'Blok baru',
        ])->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('areas', ['name' => 'Cluster Anggrek']);

        // audit log written
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'Area',
            'action' => 'CREATE',
        ]);
    }

    public function test_supervisor_cannot_access_admin_crud(): void
    {
        $supervisor = $this->createUser(RoleName::SUPERVISOR);

        $this->actingAs($supervisor)->postJson('/api/v1/admin/areas', [
            'name' => 'Cluster Mawar',
        ])->assertStatus(403);

        $this->actingAs($supervisor)->getJson('/api/v1/admin/users')
            ->assertStatus(403);
    }

    public function test_security_cannot_access_admin_crud(): void
    {
        $security = $this->createUser(RoleName::SECURITY);

        $this->actingAs($security)->getJson('/api/v1/admin/users')
            ->assertStatus(403);
    }

    public function test_supervisor_can_access_dashboard_and_reports(): void
    {
        $supervisor = $this->createUser(RoleName::SUPERVISOR);

        $this->actingAs($supervisor)->getJson('/api/v1/dashboard/stats')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->actingAs($supervisor)->getJson('/api/v1/reports/daily')
            ->assertStatus(200);
    }

    public function test_create_user_validates_unique_username(): void
    {
        $admin = $this->createUser(RoleName::SUPER_ADMIN);

        $roleId = \App\Models\Role::where('name', RoleName::SUPER_ADMIN)->first()->id;

        $payload = [
            'role_id' => $roleId,
            'employee_code' => 'ADM001',
            'name' => 'Another Admin',
            'username' => 'admin_dup',
            'password' => 'secret123',
        ];

        $this->actingAs($admin)->postJson('/api/v1/admin/users', $payload)->assertStatus(201);
        $this->actingAs($admin)->postJson('/api/v1/admin/users', $payload)->assertStatus(422);
    }

    public function test_checkpoint_create_generates_qr_token(): void
    {
        $admin = $this->createUser(RoleName::SUPER_ADMIN);
        $area = $this->createArea('Area QR');

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/checkpoints', [
            'area_id' => $area->id,
            'code' => 'cp-qr-1',
            'name' => 'Pos Uji',
            'latitude' => -6.26,
            'longitude' => 106.79,
            'radius_meter' => 30,
        ]);

        $response->assertStatus(201);

        $checkpoint = \App\Models\Checkpoint::where('code', 'CP-QR-1')->first();
        $this->assertNotNull($checkpoint);
        $this->assertStringStartsWith('PATROL:CP-QR-1:', $checkpoint->qr_token);
        $this->assertGreaterThan(20, strlen($checkpoint->qr_token));
    }

    public function test_full_admin_crud_flow(): void
    {
        $admin = $this->createUser(RoleName::SUPER_ADMIN);

        // area
        $areaId = $this->actingAs($admin)->postJson('/api/v1/admin/areas', ['name' => 'Cluster Kaktus'])
            ->assertStatus(201)
            ->json('data.id');

        // checkpoint
        $cpId = $this->actingAs($admin)->postJson('/api/v1/admin/checkpoints', [
            'area_id' => $areaId,
            'code' => 'CP777',
            'name' => 'Pos 777',
            'latitude' => -6.260000,
            'longitude' => 106.790000,
        ])->assertStatus(201)
            ->json('data.id');

        // update radius
        $this->actingAs($admin)->putJson("/api/v1/admin/checkpoints/{$cpId}", [
            'radius_meter' => 50,
        ])->assertStatus(200)
            ->assertJsonPath('data.radius_meter', 50);

        // route with the checkpoint
        $routeId = $this->actingAs($admin)->postJson('/api/v1/admin/routes', [
            'area_id' => $areaId,
            'name' => 'Rute 777',
            'route_type' => 'SEQUENTIAL',
            'checkpoints' => [
                ['checkpoint_id' => $cpId, 'sequence' => 1, 'is_required' => true],
            ],
        ])->assertStatus(201)
            ->json('data.id');

        // schedule with assignment
        $security = $this->createUser(RoleName::SECURITY);
        $scheduleId = $this->actingAs($admin)->postJson('/api/v1/admin/schedules', [
            'route_id' => $routeId,
            'name' => 'Jadwal 777',
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'user_ids' => [$security->id],
        ])->assertStatus(201)
            ->json('data.id');

        // verify relations
        $this->actingAs($admin)->getJson("/api/v1/admin/schedules/{$scheduleId}")
            ->assertStatus(200)
            ->assertJsonPath('data.assigned_users.0.id', $security->id)
            ->assertJsonPath('data.total_checkpoint', 1);

        // cleanup
        $this->actingAs($admin)->deleteJson("/api/v1/admin/schedules/{$scheduleId}")->assertStatus(200);
        $this->actingAs($admin)->deleteJson("/api/v1/admin/routes/{$routeId}")->assertStatus(200);
        $this->actingAs($admin)->deleteJson("/api/v1/admin/checkpoints/{$cpId}")->assertStatus(200);
        $this->actingAs($admin)->deleteJson("/api/v1/admin/areas/{$areaId}")->assertStatus(200);
    }
}
