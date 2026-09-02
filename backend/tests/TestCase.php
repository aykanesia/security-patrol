<?php

namespace Tests;

use App\Enums\RoleName;
use App\Models\Area;
use App\Models\Checkpoint;
use App\Models\Device;
use App\Models\PatrolRoute;
use App\Models\PatrolSchedule;
use App\Models\PatrolScheduleAssignment;
use App\Models\Role;
use App\Models\RouteCheckpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Fresh migration per test is handled by RefreshDatabase in each trait user.
     */

    protected function createRole(string $name): Role
    {
        return Role::create(['name' => $name, 'description' => $name]);
    }

    protected function createUser(RoleName|string $roleName = RoleName::SECURITY, array $overrides = []): User
    {
        $roleName = $roleName instanceof RoleName ? $roleName->value : $roleName;
        $role = Role::firstOrCreate(['name' => $roleName], ['description' => $roleName]);

        $user = User::create(array_merge([
            'role_id' => $role->id,
            'employee_code' => 'EMP' . random_int(10000, 99999),
            'name' => 'User ' . $roleName . random_int(100, 999),
            'username' => 'user_' . $roleName . random_int(1000, 9999),
            'password' => 'password',
            'phone' => '0812' . random_int(10000000, 99999999),
            'status' => 'ACTIVE',
        ], $overrides));

        return $user;
    }

    protected function createSecurityWithDevice(): array
    {
        $user = $this->createUser(RoleName::SECURITY);
        $device = Device::create([
            'user_id' => $user->id,
            'device_uuid' => 'DEV-' . strtoupper(uniqid()),
            'device_name' => 'Test Android',
            'platform' => 'android',
            'app_version' => '1.0.0',
            'status' => 'ACTIVE',
        ]);

        return [$user, $device];
    }

    protected function createArea(string $name = 'Area Test'): Area
    {
        return Area::create(['name' => $name, 'description' => 'test', 'status' => 'ACTIVE']);
    }

    protected function createCheckpoint(Area $area, string $code, float $lat, float $lon, int $radius = 30): Checkpoint
    {
        return Checkpoint::create([
            'area_id' => $area->id,
            'code' => $code,
            'name' => 'CP ' . $code,
            'latitude' => $lat,
            'longitude' => $lon,
            'radius_meter' => $radius,
            'status' => 'ACTIVE',
        ]);
    }

    protected function createRouteWithCheckpoints(Area $area, array $checkpoints, string $type = 'SEQUENTIAL'): PatrolRoute
    {
        $route = PatrolRoute::create([
            'area_id' => $area->id,
            'name' => 'Rute ' . $area->name . random_int(10, 99),
            'route_type' => $type,
            'status' => 'ACTIVE',
        ]);

        $seq = 1;
        foreach ($checkpoints as $cp) {
            RouteCheckpoint::create([
                'route_id' => $route->id,
                'checkpoint_id' => $cp->id,
                'sequence' => $seq++,
                'is_required' => true,
            ]);
        }

        return $route;
    }

    /**
     * Build a complete patrol scenario: security + device + route + schedule
     * whose time window covers "now" so startPatrol passes.
     */
    protected function makePatrolScenario(string $routeType = 'SEQUENTIAL', int $numCheckpoints = 3): array
    {
        $area = $this->createArea('Area ' . random_int(100, 999));
        $checkpoints = [];
        $lat = -6.26000000;
        $lon = 106.79000000;

        for ($i = 0; $i < $numCheckpoints; $i++) {
            $checkpoints[] = $this->createCheckpoint($area, 'CP' . $i . random_int(10, 99), $lat + $i * 0.001, $lon + $i * 0.001, 30);
        }

        $route = $this->createRouteWithCheckpoints($area, $checkpoints, $routeType);

        [$user, $device] = $this->createSecurityWithDevice();

        $schedule = PatrolSchedule::create([
            'route_id' => $route->id,
            'name' => 'Jadwal Sekarang',
            'day_of_week' => (int) now()->format('w'),
            'start_time' => now()->subHour()->format('H:i:s'),
            'end_time' => now()->addHour()->format('H:i:s'),
            'grace_before_minutes' => 60,
            'grace_after_minutes' => 60,
            'status' => 'ACTIVE',
        ]);

        PatrolScheduleAssignment::create([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
        ]);

        return [
            'user' => $user,
            'device' => $device,
            'area' => $area,
            'route' => $route,
            'schedule' => $schedule,
            'checkpoints' => $checkpoints,
        ];
    }
}
