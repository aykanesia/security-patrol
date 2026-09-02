<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\PatrolCheckin;
use App\Models\PatrolSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    private function beginPatrolSession(array $scenario): string
    {
        $cps = $scenario['checkpoints'];

        $response = $this->actingAs($scenario['user'])->postJson('/api/v1/patrol/start', [
            'schedule_id' => $scenario['schedule']->id,
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
            'device_uuid' => $scenario['device']->device_uuid,
        ]);
        $response->assertStatus(201);

        return $response->json('data.session_code');
    }

    public function test_offline_sync_processes_valid_items(): void
    {
        $scenario = $this->makePatrolScenario('FLEXIBLE', 2);
        $user = $scenario['user'];
        $cps = $scenario['checkpoints'];
        $sessionCode = $this->beginPatrolSession($scenario);

        $response = $this->actingAs($user)->postJson('/api/v1/sync', [
            'items' => [
                [
                    'uuid' => (string) Str::uuid(),
                    'session_code' => $sessionCode,
                    'checkpoint_code' => $cps[0]->code,
                    'latitude' => (float) $cps[0]->latitude,
                    'longitude' => (float) $cps[0]->longitude,
                    'gps_accuracy' => 6.0,
                    'device_timestamp' => now()->format('Y-m-d H:i:s'),
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'session_code' => $sessionCode,
                    'checkpoint_code' => $cps[1]->code,
                    'latitude' => (float) $cps[1]->latitude,
                    'longitude' => (float) $cps[1]->longitude,
                    'device_timestamp' => now()->format('Y-m-d H:i:s'),
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.summary.processed', 2)
            ->assertJsonPath('data.summary.success', 2)
            ->assertJsonPath('data.summary.duplicate', 0)
            ->assertJsonPath('data.summary.failed', 0);
    }

    public function test_offline_sync_is_idempotent_for_duplicate_uuids(): void
    {
        $scenario = $this->makePatrolScenario('FLEXIBLE', 1);
        $user = $scenario['user'];
        $cps = $scenario['checkpoints'];
        $sessionCode = $this->beginPatrolSession($scenario);

        $uuid = (string) Str::uuid();

        $item = [
            'uuid' => $uuid,
            'session_code' => $sessionCode,
            'checkpoint_code' => $cps[0]->code,
            'latitude' => (float) $cps[0]->latitude,
            'longitude' => (float) $cps[0]->longitude,
        ];

        $this->actingAs($user)->postJson('/api/v1/sync', ['items' => [$item]])
            ->assertJsonPath('data.summary.success', 1);

        // replay same batch → duplicate
        $this->actingAs($user)->postJson('/api/v1/sync', ['items' => [$item]])
            ->assertJsonPath('data.summary.success', 0)
            ->assertJsonPath('data.summary.duplicate', 1);

        $sessionId = PatrolSession::where('session_code', $sessionCode)->first()->id;
        $this->assertEquals(1, PatrolCheckin::where('session_id', $sessionId)->where('validation_status', 'VALID')->count());
    }

    public function test_offline_sync_reports_invalid_location_per_item(): void
    {
        $scenario = $this->makePatrolScenario('FLEXIBLE', 1);
        $user = $scenario['user'];
        $cps = $scenario['checkpoints'];
        $sessionCode = $this->beginPatrolSession($scenario);

        // item too far from checkpoint
        $response = $this->actingAs($user)->postJson('/api/v1/sync', [
            'items' => [
                [
                    'uuid' => (string) Str::uuid(),
                    'session_code' => $sessionCode,
                    'checkpoint_code' => $cps[0]->code,
                    'latitude' => (float) $cps[0]->latitude - 0.01,
                    'longitude' => (float) $cps[0]->longitude,
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.summary.failed', 1)
            ->assertJsonPath('data.items.0.error_code', 'INVALID_LOCATION');
    }
}
