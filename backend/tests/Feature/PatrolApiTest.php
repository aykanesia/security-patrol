<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\PatrolCheckin;
use App\Models\PatrolSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatrolApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    // ====================================================== START PATROL

    public function test_security_can_start_patrol(): void
    {
        $scenario = $this->makePatrolScenario();
        $user = $scenario['user'];

        $response = $this->actingAs($user)->postJson('/api/v1/patrol/start', [
            'schedule_id' => $scenario['schedule']->id,
            'latitude' => -6.26000000,
            'longitude' => 106.79000000,
            'device_uuid' => $scenario['device']->device_uuid,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'RUNNING')
            ->assertJsonPath('data.total_checkpoint', count($scenario['checkpoints']));

        $this->assertDatabaseHas('patrol_sessions', [
            'user_id' => $user->id,
            'status' => 'RUNNING',
        ]);
    }

    public function test_cannot_start_patrol_if_already_running(): void
    {
        $scenario = $this->makePatrolScenario();
        $user = $scenario['user'];

        $payload = [
            'schedule_id' => $scenario['schedule']->id,
            'latitude' => -6.26000000,
            'longitude' => 106.79000000,
            'device_uuid' => $scenario['device']->device_uuid,
        ];

        $this->actingAs($user)->postJson('/api/v1/patrol/start', $payload)->assertStatus(201);
        $this->actingAs($user)->postJson('/api/v1/patrol/start', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'SESSION_ALREADY_RUNNING');
    }

    public function test_supervisor_cannot_start_patrol(): void
    {
        $scenario = $this->makePatrolScenario();
        $supervisor = $this->createUser(RoleName::SUPERVISOR);

        $this->actingAs($supervisor)->postJson('/api/v1/patrol/start', [
            'schedule_id' => $scenario['schedule']->id,
            'latitude' => -6.26000000,
            'longitude' => 106.79000000,
            'device_uuid' => $scenario['device']->device_uuid,
        ])->assertStatus(403);
    }

    // ====================================================== SCAN VALID

    public function test_full_patrol_flow_sequential(): void
    {
        $scenario = $this->makePatrolScenario('SEQUENTIAL', 3);
        $user = $scenario['user'];
        $cps = $scenario['checkpoints'];

        $start = $this->actingAs($user)->postJson('/api/v1/patrol/start', [
            'schedule_id' => $scenario['schedule']->id,
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
            'device_uuid' => $scenario['device']->device_uuid,
        ]);
        $start->assertStatus(201);
        $sessionCode = $start->json('data.session_code');

        // scan cp[0] (near exact location)
        $this->actingAs($user)->postJson('/api/v1/patrol/checkpoint/scan', [
            'session_code' => $sessionCode,
            'scan_code' => $cps[0]->qr_token, // full QR token form
            'uuid' => (string) Str::uuid(),
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
            'gps_accuracy' => 5.0,
            'device_timestamp' => now()->format('Y-m-d H:i:s'),
        ])->assertStatus(200)
            ->assertJsonPath('data.validation_status', 'VALID')
            ->assertJsonPath('data.progress.completed', 1);

        // scan cp[1]
        $this->actingAs($user)->postJson('/api/v1/patrol/checkpoint/scan', [
            'session_code' => $sessionCode,
            'scan_code' => $cps[1]->code, // plain code form also works
            'uuid' => (string) Str::uuid(),
            'latitude' => (float) $cps[1]->latitude,
            'longitude' => (float) $cps[1]->longitude,
        ])->assertStatus(200)
            ->assertJsonPath('data.validation_status', 'VALID')
            ->assertJsonPath('data.progress.completed', 2);

        // scan cp[2]
        $this->actingAs($user)->postJson('/api/v1/patrol/checkpoint/scan', [
            'session_code' => $sessionCode,
            'scan_code' => $cps[2]->code,
            'uuid' => (string) Str::uuid(),
            'latitude' => (float) $cps[2]->latitude,
            'longitude' => (float) $cps[2]->longitude,
        ])->assertStatus(200)
            ->assertJsonPath('data.progress.completed', 3)
            ->assertJsonPath('data.progress.percentage', 100);

        // complete
        $this->actingAs($user)->postJson('/api/v1/patrol/complete', [
            'session_code' => $sessionCode,
            'latitude' => (float) $cps[2]->latitude,
            'longitude' => (float) $cps[2]->longitude,
        ])->assertStatus(200)
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertDatabaseHas('patrol_sessions', [
            'session_code' => $sessionCode,
            'status' => 'COMPLETED',
            'completed_checkpoint' => 3,
        ]);
    }

    // ====================================================== GPS FAIL

    public function test_scan_outside_radius_fails_with_invalid_location(): void
    {
        $scenario = $this->makePatrolScenario('SEQUENTIAL', 2);
        $user = $scenario['user'];
        $cps = $scenario['checkpoints'];

        $start = $this->actingAs($user)->postJson('/api/v1/patrol/start', [
            'schedule_id' => $scenario['schedule']->id,
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
            'device_uuid' => $scenario['device']->device_uuid,
        ]);
        $sessionCode = $start->json('data.session_code');

        // move ~500m away from checkpoint
        $farLat = (float) $cps[0]->latitude - 0.005;

        $this->actingAs($user)->postJson('/api/v1/patrol/checkpoint/scan', [
            'session_code' => $sessionCode,
            'scan_code' => $cps[0]->code,
            'uuid' => (string) Str::uuid(),
            'latitude' => $farLat,
            'longitude' => (float) $cps[0]->longitude,
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_LOCATION')
            ->assertJsonPath('data.allowed_radius', 30);

        // failed attempt recorded with INVALID_LOCATION
        $this->assertDatabaseHas('patrol_checkins', [
            'session_id' => PatrolSession::where('session_code', $sessionCode)->first()->id,
            'checkpoint_id' => $cps[0]->id,
            'validation_status' => 'INVALID_LOCATION',
        ]);
    }

    // ====================================================== DUPLICATE

    public function test_scan_duplicate_checkpoint_fails(): void
    {
        $scenario = $this->makePatrolScenario('FLEXIBLE', 2);
        $user = $scenario['user'];
        $cps = $scenario['checkpoints'];

        $start = $this->actingAs($user)->postJson('/api/v1/patrol/start', [
            'schedule_id' => $scenario['schedule']->id,
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
            'device_uuid' => $scenario['device']->device_uuid,
        ]);
        $sessionCode = $start->json('data.session_code');

        $payload = [
            'session_code' => $sessionCode,
            'scan_code' => $cps[0]->code,
            'uuid' => (string) Str::uuid(),
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
        ];

        $this->actingAs($user)->postJson('/api/v1/patrol/checkpoint/scan', $payload)->assertStatus(200);

        // second attempt with a NEW uuid → duplicate checkpoint error
        $this->actingAs($user)->postJson('/api/v1/patrol/checkpoint/scan', [
            'session_code' => $sessionCode,
            'scan_code' => $cps[0]->code,
            'uuid' => (string) Str::uuid(),
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'DUPLICATE_CHECKIN');
    }

    // ====================================================== IDEMPOTENCY

    public function test_same_uuid_not_inserted_twice(): void
    {
        $scenario = $this->makePatrolScenario('FLEXIBLE', 2);
        $user = $scenario['user'];
        $cps = $scenario['checkpoints'];
        $uuid = (string) Str::uuid();

        $start = $this->actingAs($user)->postJson('/api/v1/patrol/start', [
            'schedule_id' => $scenario['schedule']->id,
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
            'device_uuid' => $scenario['device']->device_uuid,
        ]);
        $sessionCode = $start->json('data.session_code');

        $payload = [
            'session_code' => $sessionCode,
            'scan_code' => $cps[0]->code,
            'uuid' => $uuid,
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
        ];

        $this->actingAs($user)->postJson('/api/v1/patrol/checkpoint/scan', $payload)->assertStatus(200);

        // second identical request with same uuid → not inserted (idempotent response)
        $this->actingAs($user)->postJson('/api/v1/patrol/checkpoint/scan', $payload)
            ->assertJsonPath('success', false);

        $this->assertEquals(1, PatrolCheckin::where('session_id', PatrolSession::where('session_code', $sessionCode)->first()->id)
            ->where('validation_status', 'VALID')
            ->count());
    }

    // ====================================================== SEQUENCE

    public function test_sequential_route_rejects_out_of_order(): void
    {
        $scenario = $this->makePatrolScenario('SEQUENTIAL', 3);
        $user = $scenario['user'];
        $cps = $scenario['checkpoints'];

        $start = $this->actingAs($user)->postJson('/api/v1/patrol/start', [
            'schedule_id' => $scenario['schedule']->id,
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
            'device_uuid' => $scenario['device']->device_uuid,
        ]);
        $sessionCode = $start->json('data.session_code');

        // try scanning cp[2] (sequence 3) first → INVALID_SEQUENCE
        $this->actingAs($user)->postJson('/api/v1/patrol/checkpoint/scan', [
            'session_code' => $sessionCode,
            'scan_code' => $cps[2]->code,
            'uuid' => (string) Str::uuid(),
            'latitude' => (float) $cps[2]->latitude,
            'longitude' => (float) $cps[2]->longitude,
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_SEQUENCE')
            ->assertJsonPath('data.required_checkpoint', $cps[0]->code);
    }

    public function test_flexible_route_allows_any_order(): void
    {
        $scenario = $this->makePatrolScenario('FLEXIBLE', 3);
        $user = $scenario['user'];
        $cps = $scenario['checkpoints'];

        $start = $this->actingAs($user)->postJson('/api/v1/patrol/start', [
            'schedule_id' => $scenario['schedule']->id,
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
            'device_uuid' => $scenario['device']->device_uuid,
        ]);
        $sessionCode = $start->json('data.session_code');

        // scan LAST checkpoint first — should succeed on flexible route
        $this->actingAs($user)->postJson('/api/v1/patrol/checkpoint/scan', [
            'session_code' => $sessionCode,
            'scan_code' => $cps[2]->code,
            'uuid' => (string) Str::uuid(),
            'latitude' => (float) $cps[2]->latitude,
            'longitude' => (float) $cps[2]->longitude,
        ])->assertStatus(200)
            ->assertJsonPath('data.validation_status', 'VALID');
    }

    // ====================================================== COMPLETE

    public function test_complete_with_missing_checkpoint_fails(): void
    {
        $scenario = $this->makePatrolScenario('SEQUENTIAL', 3);
        $user = $scenario['user'];
        $cps = $scenario['checkpoints'];

        $start = $this->actingAs($user)->postJson('/api/v1/patrol/start', [
            'schedule_id' => $scenario['schedule']->id,
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
            'device_uuid' => $scenario['device']->device_uuid,
        ]);
        $sessionCode = $start->json('data.session_code');

        // only 1 of 3 visited
        $this->actingAs($user)->postJson('/api/v1/patrol/checkpoint/scan', [
            'session_code' => $sessionCode,
            'scan_code' => $cps[0]->code,
            'uuid' => (string) Str::uuid(),
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/v1/patrol/complete', [
            'session_code' => $sessionCode,
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'CHECKPOINT_INCOMPLETE');
    }

    // ====================================================== CURRENT

    public function test_current_patrol_returns_progress(): void
    {
        $scenario = $this->makePatrolScenario('SEQUENTIAL', 2);
        $user = $scenario['user'];
        $cps = $scenario['checkpoints'];

        $start = $this->actingAs($user)->postJson('/api/v1/patrol/start', [
            'schedule_id' => $scenario['schedule']->id,
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
            'device_uuid' => $scenario['device']->device_uuid,
        ]);
        $sessionCode = $start->json('data.session_code');

        $this->actingAs($user)->getJson('/api/v1/patrol/current')
            ->assertStatus(200)
            ->assertJsonPath('data.session.session_code', $sessionCode)
            ->assertJsonPath('data.progress_percentage', 0);

        $this->actingAs($user)->postJson('/api/v1/patrol/checkpoint/scan', [
            'session_code' => $sessionCode,
            'scan_code' => $cps[0]->code,
            'uuid' => (string) Str::uuid(),
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
        ])->assertStatus(200);

        $this->actingAs($user)->getJson('/api/v1/patrol/current')
            ->assertJsonPath('data.progress_percentage', 50);
    }

    // ====================================================== HISTORY

    public function test_history_returns_only_own_sessions(): void
    {
        $scenario = $this->makePatrolScenario('FLEXIBLE', 1);
        $user = $scenario['user'];
        $other = $this->createUser(RoleName::SECURITY);
        $cps = $scenario['checkpoints'];

        // user starts a patrol
        $start = $this->actingAs($user)->postJson('/api/v1/patrol/start', [
            'schedule_id' => $scenario['schedule']->id,
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
            'device_uuid' => $scenario['device']->device_uuid,
        ]);
        $this->actingAs($user)->postJson('/api/v1/patrol/cancel', [
            'session_code' => $start->json('data.session_code'),
            'reason' => 'test',
        ])->assertStatus(200);

        // other user has no sessions
        $this->actingAs($other)->getJson('/api/v1/patrol/history')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($user)->getJson('/api/v1/patrol/history')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'CANCELLED');
    }
}
