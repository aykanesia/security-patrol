<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Checkpoint;
use App\Models\PatrolRoute;
use App\Models\PatrolSchedule;
use App\Models\PatrolScheduleAssignment;
use App\Models\RouteCheckpoint;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo master data: 2 areas, checkpoints (real-ish coords around a
 * residential complex), routes + schedules assigned to demo security.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        // ----------------------------------------------------------------- areas
        $areaA = Area::updateOrCreate(['name' => 'Cluster Mawar'], [
            'description' => 'Cluster Mawar - blok A sampai D',
            'status' => 'ACTIVE',
        ]);
        $areaB = Area::updateOrCreate(['name' => 'Cluster Melati'], [
            'description' => 'Cluster Melati - blok E sampai H',
            'status' => 'ACTIVE',
        ]);

        // ---------------------------------------------------------- checkpoints
        // Base coordinate: south Jakarta residential (~ -6.26, 106.79)
        $checkpoints = [
            // cluster mawar
            ['area' => $areaA, 'code' => 'CP001', 'name' => 'Pos Utama', 'lat' => -6.26000000, 'lon' => 106.79000000, 'radius' => 30],
            ['area' => $areaA, 'code' => 'CP002', 'name' => 'Blok A', 'lat' => -6.26040000, 'lon' => 106.79040000, 'radius' => 30],
            ['area' => $areaA, 'code' => 'CP003', 'name' => 'Blok B', 'lat' => -6.26080000, 'lon' => 106.79020000, 'radius' => 30],
            ['area' => $areaA, 'code' => 'CP004', 'name' => 'Taman Tengah', 'lat' => -6.26120000, 'lon' => 106.79060000, 'radius' => 25],
            ['area' => $areaA, 'code' => 'CP005', 'name' => 'Gerbang Belakang', 'lat' => -6.26160000, 'lon' => 106.79090000, 'radius' => 30],
            // cluster melati
            ['area' => $areaB, 'code' => 'CP101', 'name' => 'Pos Melati', 'lat' => -6.26500000, 'lon' => 106.79500000, 'radius' => 30],
            ['area' => $areaB, 'code' => 'CP102', 'name' => 'Blok E', 'lat' => -6.26550000, 'lon' => 106.79530000, 'radius' => 30],
            ['area' => $areaB, 'code' => 'CP103', 'name' => 'Kolam Renang', 'lat' => -6.26590000, 'lon' => 106.79560000, 'radius' => 25],
            ['area' => $areaB, 'code' => 'CP104', 'name' => 'Blok G', 'lat' => -6.26630000, 'lon' => 106.79520000, 'radius' => 30],
        ];

        $cpModels = [];
        foreach ($checkpoints as $cp) {
            $model = Checkpoint::updateOrCreate(
                ['code' => $cp['code']],
                [
                    'area_id' => $cp['area']->id,
                    'name' => $cp['name'],
                    'description' => 'Checkpoint ' . $cp['name'],
                    'latitude' => $cp['lat'],
                    'longitude' => $cp['lon'],
                    'radius_meter' => $cp['radius'],
                    'status' => 'ACTIVE',
                ],
            );
            // updateOrCreate keeps old qr_token (unique) — safe.
            $cpModels[$cp['code']] = $model;
        }

        // ----------------------------------------------------------------- routes
        $routeMalamA = PatrolRoute::updateOrCreate(
            ['name' => 'Rute Malam Cluster Mawar'],
            ['area_id' => $areaA->id, 'description' => 'Patroli malam keliling Cluster Mawar', 'route_type' => 'SEQUENTIAL', 'status' => 'ACTIVE'],
        );
        $routeMalamB = PatrolRoute::updateOrCreate(
            ['name' => 'Rute Malam Cluster Melati'],
            ['area_id' => $areaB->id, 'description' => 'Patroli malam keliling Cluster Melati', 'route_type' => 'FLEXIBLE', 'status' => 'ACTIVE'],
        );

        $this->syncRouteCheckpoints($routeMalamA->id, [
            ['checkpoint' => $cpModels['CP001'], 'sequence' => 1, 'required' => true],
            ['checkpoint' => $cpModels['CP002'], 'sequence' => 2, 'required' => true],
            ['checkpoint' => $cpModels['CP003'], 'sequence' => 3, 'required' => true],
            ['checkpoint' => $cpModels['CP004'], 'sequence' => 4, 'required' => true],
            ['checkpoint' => $cpModels['CP005'], 'sequence' => 5, 'required' => true],
        ]);
        $this->syncRouteCheckpoints($routeMalamB->id, [
            ['checkpoint' => $cpModels['CP101'], 'sequence' => 1, 'required' => true],
            ['checkpoint' => $cpModels['CP102'], 'sequence' => 2, 'required' => true],
            ['checkpoint' => $cpModels['CP103'], 'sequence' => 3, 'required' => true],
            ['checkpoint' => $cpModels['CP104'], 'sequence' => 4, 'required' => false],
        ]);

        // ------------------------------------------------------------- schedules
        $budi = User::where('username', 'budi')->firstOrFail();
        $andi = User::where('username', 'andi')->firstOrFail();
        $citra = User::where('username', 'citra')->firstOrFail();

        // nightly patrol, every day (day_of_week null)
        $scheduleMalam = PatrolSchedule::updateOrCreate(
            ['name' => 'Patroli Malam 22:00'],
            [
                'route_id' => $routeMalamA->id,
                'day_of_week' => null, // daily
                'start_time' => '22:00:00',
                'end_time' => '23:00:00',
                'grace_before_minutes' => 15,
                'grace_after_minutes' => 15,
                'status' => 'ACTIVE',
            ],
        );

        $scheduleMalamB = PatrolSchedule::updateOrCreate(
            ['name' => 'Patroli Malam Melati'],
            [
                'route_id' => $routeMalamB->id,
                'day_of_week' => null,
                'start_time' => '22:00:00',
                'end_time' => '23:00:00',
                'grace_before_minutes' => 15,
                'grace_after_minutes' => 15,
                'status' => 'ACTIVE',
            ],
        );

        // morning patrol (Mon-Sat), for citra
        $schedulePagi = PatrolSchedule::updateOrCreate(
            ['name' => 'Patroli Pagi 06:00'],
            [
                'route_id' => $routeMalamA->id,
                'day_of_week' => null,
                'start_time' => '06:00:00',
                'end_time' => '07:00:00',
                'grace_before_minutes' => 10,
                'grace_after_minutes' => 10,
                'status' => 'ACTIVE',
            ],
        );

        $this->syncAssignments($scheduleMalam->id, [$budi->id, $andi->id]);
        $this->syncAssignments($scheduleMalamB->id, [$citra->id]);
        $this->syncAssignments($schedulePagi->id, [$budi->id]);
    }

    private function syncRouteCheckpoints(int $routeId, array $items): void
    {
        RouteCheckpoint::where('route_id', $routeId)->delete();

        foreach ($items as $item) {
            RouteCheckpoint::create([
                'route_id' => $routeId,
                'checkpoint_id' => $item['checkpoint']->id,
                'sequence' => $item['sequence'],
                'is_required' => $item['required'],
            ]);
        }
    }

    private function syncAssignments(int $scheduleId, array $userIds): void
    {
        PatrolScheduleAssignment::where('schedule_id', $scheduleId)->delete();

        foreach ($userIds as $userId) {
            PatrolScheduleAssignment::create([
                'schedule_id' => $scheduleId,
                'user_id' => $userId,
            ]);
        }
    }
}
