<?php

namespace App\Services;

use App\Enums\PatrolSessionStatus;
use App\Models\Device;
use App\Models\PatrolCheckin;
use App\Models\PatrolSession;
use App\Models\User;
use Carbon\Carbon;

/**
 * Aggregations for the web dashboard (today stats + active patrols map).
 */
class DashboardService
{
    public function stats(?Carbon $date = null): array
    {
        $date ??= now();

        $sessions = PatrolSession::whereDate('started_at', $date->toDateString())->get();

        return [
            'date' => $date->toDateString(),
            'total_patrol' => $sessions->count(),
            'completed' => $sessions->where('status', PatrolSessionStatus::COMPLETED->value)->count(),
            'running' => $sessions->where('status', PatrolSessionStatus::RUNNING->value)->count(),
            'incomplete' => $sessions->where('status', PatrolSessionStatus::INCOMPLETE->value)->count(),
            'cancelled' => $sessions->where('status', PatrolSessionStatus::CANCELLED->value)->count(),
            'active_officers' => User::whereHas('role', fn ($q) => $q->where('name', 'security'))
                ->where('status', 'ACTIVE')
                ->count(),
            'active_checkpoints' => \App\Models\Checkpoint::where('status', 'ACTIVE')->count(),
        ];
    }

    /**
     * Running patrol sessions with officer position + progress, for live map.
     */
    public function activePatrols(): array
    {
        return PatrolSession::query()
            ->with(['user', 'route.area', 'route.routeCheckpoints.checkpoint', 'device'])
            ->where('status', PatrolSessionStatus::RUNNING->value)
            ->latest('started_at')
            ->get()
            ->map(function (PatrolSession $session) {
                $validCheckins = PatrolCheckin::where('session_id', $session->id)
                    ->where('validation_status', 'VALID')
                    ->count();

                $lastCheckin = $session->checkins()
                    ->where('validation_status', 'VALID')
                    ->latest('scanned_at')
                    ->first();

                $position = [
                    'latitude' => $lastCheckin?->latitude ?? $session->started_latitude,
                    'longitude' => $lastCheckin?->longitude ?? $session->started_longitude,
                    'source' => $lastCheckin ? 'checkin' : 'start',
                    'updated_at' => $lastCheckin?->scanned_at?->toIso8601String()
                        ?? $session->started_at?->toIso8601String(),
                ];

                return [
                    'session_code' => $session->session_code,
                    'status' => $session->status,
                    'started_at' => $session->started_at?->toIso8601String(),
                    'progress' => [
                        'completed' => $validCheckins,
                        'total' => $session->total_checkpoint,
                        'percentage' => $session->total_checkpoint > 0
                            ? (int) round($validCheckins / $session->total_checkpoint * 100)
                            : 0,
                    ],
                    'officer' => [
                        'id' => $session->user->id,
                        'name' => $session->user->name,
                        'employee_code' => $session->user->employee_code,
                        'phone' => $session->user->phone,
                    ],
                    'route' => [
                        'id' => $session->route->id,
                        'name' => $session->route->name,
                        'area' => $session->route->area?->name,
                    ],
                    'position' => $position,
                    'checkpoints' => $session->route->routeCheckpoints->map(
                        fn ($rc) => [
                            'id' => $rc->checkpoint->id,
                            'code' => $rc->checkpoint->code,
                            'name' => $rc->checkpoint->name,
                            'latitude' => (float) $rc->checkpoint->latitude,
                            'longitude' => (float) $rc->checkpoint->longitude,
                            'sequence' => $rc->sequence,
                        ],
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Latest officer device positions (last seen), optionally filtered.
     */
    public function officerPositions(): array
    {
        return Device::query()
            ->with('user')
            ->where('status', 'ACTIVE')
            ->whereNotNull('last_latitude')
            ->whereNotNull('last_longitude')
            ->latest('last_seen_at')
            ->get()
            ->map(fn (Device $device) => [
                'officer' => ['id' => $device->user->id, 'name' => $device->user->name],
                'device' => $device->device_name,
                'latitude' => (float) $device->last_latitude,
                'longitude' => (float) $device->last_longitude,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            ])
            ->all();
    }
}
