<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\PatrolService;
use Illuminate\Http\Request;

/**
 * Patrol endpoints consumed by the Android security app.
 */
class PatrolController extends Controller
{
    public function __construct(private readonly PatrolService $patrol) {}

    /**
     * GET /patrol/schedules/today — security's schedules for today.
     */
    public function todaySchedules(Request $request)
    {
        $dayOfWeek = (int) now()->format('w');

        $schedules = \App\Models\PatrolSchedule::query()
            ->with(['route.area', 'route.routeCheckpoints'])
            ->where('status', 'ACTIVE')
            ->whereHas('assignments', fn ($q) => $q->where('user_id', $request->user()->id))
            ->where(fn ($q) => $q->whereNull('day_of_week')->orWhere('day_of_week', $dayOfWeek))
            ->orderBy('start_time')
            ->get()
            ->map(fn ($schedule) => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'day_of_week' => $schedule->day_of_week,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'grace_before_minutes' => $schedule->grace_before_minutes,
                'grace_after_minutes' => $schedule->grace_after_minutes,
                'route' => [
                    'id' => $schedule->route->id,
                    'name' => $schedule->route->name,
                    'route_type' => $schedule->route->route_type,
                    'area' => $schedule->route->area?->name,
                    'total_checkpoint' => $schedule->route->routeCheckpoints->count(),
                ],
            ]);

        return ApiResponse::success($schedules, 'Sukses');
    }

    /**
     * POST /patrol/start
     */
    public function start(Request $request)
    {
        $validated = $request->validate([
            'schedule_id' => ['required', 'integer'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'device_uuid' => ['required', 'string', 'max:150'],
        ]);

        $session = $this->patrol->startPatrol($request->user(), $validated);

        return ApiResponse::success([
            'session_code' => $session->session_code,
            'status' => $session->status,
            'started_at' => $session->started_at->format('Y-m-d H:i:s'),
            'route' => ['id' => $session->route->id, 'name' => $session->route->name],
            'total_checkpoint' => $session->total_checkpoint,
            'completed_checkpoint' => $session->completed_checkpoint,
        ], 'Patroli dimulai', 201);
    }

    /**
     * GET /patrol/current
     */
    public function current(Request $request)
    {
        $payload = $this->patrol->getCurrentPatrol($request->user());

        if (! $payload) {
            return ApiResponse::success(null, 'Tidak ada patroli aktif');
        }

        return ApiResponse::success($payload);
    }

    /**
     * POST /patrol/checkpoint/scan
     */
    public function scan(Request $request)
    {
        $validated = $request->validate([
            'session_code' => ['required', 'string', 'max:50'],
            'scan_code' => ['required', 'string', 'max:255'], // QR token or public code
            'uuid' => ['required', 'uuid'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy' => ['nullable', 'numeric', 'min:0'],
            'device_timestamp' => ['nullable', 'date'],
            'device_uuid' => ['nullable', 'string', 'max:150'],
        ]);

        $result = $this->patrol->scanCheckpoint($request->user(), $validated);

        $checkin = $result['patrol_checkin'];
        $session = $result['session'];

        return ApiResponse::success([
            'checkpoint' => [
                'code' => $checkin->checkpoint->code,
                'name' => $checkin->checkpoint->name,
            ],
            'scanned_at' => $checkin->scanned_at->format('Y-m-d H:i:s'),
            'distance_meter' => (float) $checkin->distance_meter,
            'validation_status' => $checkin->validation_status,
            'progress' => [
                'completed' => $session->completed_checkpoint,
                'total' => $session->total_checkpoint,
                'percentage' => $session->progressPercentage(),
            ],
        ], 'Checkpoint berhasil');
    }

    /**
     * POST /patrol/complete
     */
    public function complete(Request $request)
    {
        $validated = $request->validate([
            'session_code' => ['required', 'string', 'max:50'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $session = $this->patrol->completePatrol($request->user(), $validated);

        return ApiResponse::success([
            'session_code' => $session->session_code,
            'status' => $session->status,
            'started_at' => $session->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $session->completed_at?->format('Y-m-d H:i:s'),
            'duration_seconds' => $session->durationSeconds(),
            'checkpoint_completed' => $session->completed_checkpoint,
            'checkpoint_total' => $session->total_checkpoint,
        ], 'Patroli selesai');
    }

    /**
     * POST /patrol/cancel
     */
    public function cancel(Request $request)
    {
        $validated = $request->validate([
            'session_code' => ['required', 'string', 'max:50'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $session = $this->patrol->cancelPatrol($request->user(), $validated);

        return ApiResponse::success([
            'session_code' => $session->session_code,
            'status' => $session->status,
        ], 'Patroli dibatalkan');
    }

    /**
     * GET /patrol/history
     */
    public function history(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:RUNNING,COMPLETED,INCOMPLETE,CANCELLED'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->patrol->getPatrolHistory($request->user(), $validated);

        $paginated = $query->paginate($validated['per_page'] ?? 15);

        $items = $paginated->map(fn ($session) => [
            'session_code' => $session->session_code,
            'status' => $session->status,
            'started_at' => $session->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $session->completed_at?->format('Y-m-d H:i:s'),
            'duration_seconds' => $session->durationSeconds(),
            'route' => $session->route?->name,
            'total_checkpoint' => $session->total_checkpoint,
            'completed_checkpoint' => $session->completed_checkpoint,
        ]);

        return ApiResponse::success($items->all(), 'Sukses', 200, [
            'current_page' => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'last_page' => $paginated->lastPage(),
        ]);
    }

    /**
     * GET /patrol/detail/{sessionCode}
     */
    public function detail(Request $request, string $sessionCode)
    {
        $session = $this->patrol->getPatrolDetail($request->user(), $sessionCode);

        $checkins = $session->checkins->map(fn ($c) => [
            'checkpoint' => [
                'code' => $c->checkpoint->code,
                'name' => $c->checkpoint->name,
            ],
            'scanned_at' => $c->scanned_at?->format('Y-m-d H:i:s'),
            'device_timestamp' => $c->device_timestamp?->format('Y-m-d H:i:s'),
            'latitude' => (float) $c->latitude,
            'longitude' => (float) $c->longitude,
            'distance_meter' => (float) $c->distance_meter,
            'validation_status' => $c->validation_status,
        ]);

        return ApiResponse::success([
            'session_code' => $session->session_code,
            'status' => $session->status,
            'started_at' => $session->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $session->completed_at?->format('Y-m-d H:i:s'),
            'duration_seconds' => $session->durationSeconds(),
            'officer' => [
                'id' => $session->user->id,
                'name' => $session->user->name,
                'employee_code' => $session->user->employee_code,
            ],
            'route' => [
                'id' => $session->route->id,
                'name' => $session->route->name,
                'area' => $session->route->area?->name,
            ],
            'schedule' => $session->schedule?->name,
            'total_checkpoint' => $session->total_checkpoint,
            'completed_checkpoint' => $session->completed_checkpoint,
            'checkins' => $checkins,
        ]);
    }
}
