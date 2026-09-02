<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PatrolSession;
use App\Services\AuditService;
use App\Services\PatrolService;
use Illuminate\Http\Request;

/**
 * Session supervision for web (super_admin + supervisor): list all sessions,
 * view detail, mark running session INCOMPLETE with a reason.
 */
class SessionController extends Controller
{
    public function __construct(
        private readonly PatrolService $patrol,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:RUNNING,COMPLETED,INCOMPLETE,CANCELLED'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'route_id' => ['nullable', 'integer', 'exists:patrol_routes,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PatrolSession::query()
            ->with(['user.role', 'route.area', 'schedule', 'device'])
            ->orderByDesc('started_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }
        if (! empty($validated['area_id'])) {
            $query->whereHas('route', fn ($q) => $q->where('area_id', $validated['area_id']));
        }
        if (! empty($validated['route_id'])) {
            $query->where('route_id', $validated['route_id']);
        }
        if (! empty($validated['from'])) {
            $query->whereDate('started_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->whereDate('started_at', '<=', $validated['to']);
        }
        if (! empty($validated['search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('session_code', 'like', '%' . $validated['search'] . '%')
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%' . $validated['search'] . '%'));
            });
        }

        $paginated = $query->paginate($validated['per_page'] ?? 15);

        return ApiResponse::success(
            collect($paginated->items())->map(fn (PatrolSession $s) => [
                'id' => $s->id,
                'session_code' => $s->session_code,
                'status' => $s->status,
                'started_at' => $s->started_at?->format('Y-m-d H:i:s'),
                'completed_at' => $s->completed_at?->format('Y-m-d H:i:s'),
                'duration_seconds' => $s->durationSeconds(),
                'officer' => ['id' => $s->user->id, 'name' => $s->user->name, 'employee_code' => $s->user->employee_code],
                'route' => ['id' => $s->route->id, 'name' => $s->route->name, 'area' => $s->route->area?->name],
                'schedule' => $s->schedule?->name,
                'total_checkpoint' => $s->total_checkpoint,
                'completed_checkpoint' => $s->completed_checkpoint,
                'device' => $s->device?->device_name,
            ])->all(),
            'Sukses',
            200,
            [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        );
    }

    public function show(int $id)
    {
        $session = PatrolSession::with([
            'user.role',
            'route.area',
            'schedule',
            'device',
            'checkins.checkpoint.area',
        ])->findOrFail($id);

        return ApiResponse::success([
            'id' => $session->id,
            'session_code' => $session->session_code,
            'uuid' => $session->uuid,
            'status' => $session->status,
            'started_at' => $session->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $session->completed_at?->format('Y-m-d H:i:s'),
            'duration_seconds' => $session->durationSeconds(),
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
                'route_type' => $session->route->route_type,
            ],
            'schedule' => $session->schedule ? [
                'id' => $session->schedule->id,
                'name' => $session->schedule->name,
                'start_time' => $session->schedule->start_time,
                'end_time' => $session->schedule->end_time,
            ] : null,
            'device' => $session->device ? [
                'device_uuid' => $session->device->device_uuid,
                'device_name' => $session->device->device_name,
            ] : null,
            'total_checkpoint' => $session->total_checkpoint,
            'completed_checkpoint' => $session->completed_checkpoint,
            'started_location' => [
                'latitude' => $session->started_latitude !== null ? (float) $session->started_latitude : null,
                'longitude' => $session->started_longitude !== null ? (float) $session->started_longitude : null,
            ],
            'checkins' => $session->checkins->map(fn ($c) => [
                'id' => $c->id,
                'checkpoint' => [
                    'code' => $c->checkpoint->code,
                    'name' => $c->checkpoint->name,
                    'area' => $c->checkpoint->area?->name,
                ],
                'scanned_at' => $c->scanned_at?->format('Y-m-d H:i:s'),
                'device_timestamp' => $c->device_timestamp?->format('Y-m-d H:i:s'),
                'latitude' => (float) $c->latitude,
                'longitude' => (float) $c->longitude,
                'distance_meter' => $c->distance_meter !== null ? (float) $c->distance_meter : null,
                'gps_accuracy' => $c->gps_accuracy !== null ? (float) $c->gps_accuracy : null,
                'validation_status' => $c->validation_status,
            ]),
        ]);
    }

    /**
     * POST /admin/sessions/{id}/incomplete — supervisor marks RUNNING as INCOMPLETE.
     */
    public function markIncomplete(Request $request, int $id)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $session = PatrolSession::findOrFail($id);

        $updated = $this->patrol->markIncomplete($request->user(), $session->session_code, $validated['reason'] ?? null);

        return ApiResponse::success([
            'session_code' => $updated->session_code,
            'status' => $updated->status,
            'completed_at' => $updated->completed_at?->format('Y-m-d H:i:s'),
        ], 'Patroli ditandai incomplete');
    }
}
