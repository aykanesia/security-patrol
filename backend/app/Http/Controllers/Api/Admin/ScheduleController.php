<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PatrolSchedule;
use App\Models\PatrolScheduleAssignment;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'route_id' => ['nullable', 'integer', 'exists:patrol_routes,id'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PatrolSchedule::query()
            ->with(['route.area', 'assignments.user'])
            ->withCount('assignments');

        if (! empty($validated['route_id'])) {
            $query->where('route_id', $validated['route_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (isset($validated['day_of_week'])) {
            $query->where('day_of_week', $validated['day_of_week']);
        }

        $paginated = $query->orderBy('day_of_week')->orderBy('start_time')
            ->paginate($validated['per_page'] ?? 15);

        return ApiResponse::success(
            collect($paginated->items())->map(fn ($s) => $this->payload($s))->all(),
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'route_id' => ['required', 'integer', 'exists:patrol_routes,id'],
            'name' => ['required', 'string', 'max:150'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'], // null = every day
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'grace_before_minutes' => ['nullable', 'integer', 'min:0', 'max:180'],
            'grace_after_minutes' => ['nullable', 'integer', 'min:0', 'max:180'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE'],
            'user_ids' => ['nullable', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $schedule = DB::transaction(function () use ($validated) {
            $schedule = PatrolSchedule::create([
                'route_id' => $validated['route_id'],
                'name' => $validated['name'],
                'day_of_week' => $validated['day_of_week'] ?? null,
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'grace_before_minutes' => $validated['grace_before_minutes'] ?? 15,
                'grace_after_minutes' => $validated['grace_after_minutes'] ?? 15,
                'status' => $validated['status'] ?? 'ACTIVE',
            ]);

            foreach ($validated['user_ids'] ?? [] as $userId) {
                PatrolScheduleAssignment::create([
                    'schedule_id' => $schedule->id,
                    'user_id' => $userId,
                ]);
            }

            return $schedule;
        });

        $this->audit->created('PatrolSchedule', $schedule->id, $schedule->only([
            'route_id', 'name', 'day_of_week', 'start_time', 'end_time', 'status',
        ]) + ['user_ids' => $validated['user_ids'] ?? []]);

        return ApiResponse::created($this->payload($schedule->fresh(['route.area', 'assignments.user'])), 'Jadwal berhasil dibuat');
    }

    public function show(int $id)
    {
        $schedule = PatrolSchedule::with(['route.area', 'assignments.user', 'route.routeCheckpoints.checkpoint'])
            ->findOrFail($id);

        return ApiResponse::success($this->payload($schedule, true));
    }

    public function update(Request $request, int $id)
    {
        $schedule = PatrolSchedule::findOrFail($id);

        $validated = $request->validate([
            'route_id' => ['sometimes', 'integer', 'exists:patrol_routes,id'],
            'name' => ['sometimes', 'string', 'max:150'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i', 'after:start_time'],
            'grace_before_minutes' => ['sometimes', 'integer', 'min:0', 'max:180'],
            'grace_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:180'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,INACTIVE'],
            'user_ids' => ['sometimes', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $old = $schedule->only([
            'route_id', 'name', 'day_of_week', 'start_time', 'end_time', 'status',
        ]);

        $schedule = DB::transaction(function () use ($schedule, $validated) {
            $schedule->update(collect($validated)->except('user_ids')->all());

            if (array_key_exists('user_ids', $validated)) {
                $schedule->assignments()->delete();
                foreach ($validated['user_ids'] as $userId) {
                    PatrolScheduleAssignment::create([
                        'schedule_id' => $schedule->id,
                        'user_id' => $userId,
                    ]);
                }
            }

            return $schedule;
        });

        $this->audit->updated('PatrolSchedule', $schedule->id, $old, $schedule->only([
            'route_id', 'name', 'day_of_week', 'start_time', 'end_time', 'status',
        ]) + ['user_ids' => $validated['user_ids'] ?? null]);

        return ApiResponse::success($this->payload($schedule->fresh(['route.area', 'assignments.user'])), 'Jadwal berhasil diperbarui');
    }

    public function destroy(int $id)
    {
        $schedule = PatrolSchedule::findOrFail($id);

        $schedule->delete();
        $this->audit->deleted('PatrolSchedule', $id, ['name' => $schedule->name]);

        return ApiResponse::success(null, 'Jadwal berhasil dihapus');
    }

    private function payload(PatrolSchedule $schedule, bool $detailed = false): array
    {
        $payload = [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'route_id' => $schedule->route_id,
            'route' => $schedule->route?->name,
            'area' => $schedule->route?->area?->name,
            'day_of_week' => $schedule->day_of_week, // null = daily
            'start_time' => $schedule->start_time,
            'end_time' => $schedule->end_time,
            'grace_before_minutes' => $schedule->grace_before_minutes,
            'grace_after_minutes' => $schedule->grace_after_minutes,
            'status' => $schedule->status,
            'assignments_count' => $schedule->assignments_count ?? $schedule->assignments()->count(),
            'assigned_users' => $schedule->assignments->map(fn ($a) => [
                'id' => $a->user->id,
                'name' => $a->user->name,
                'employee_code' => $a->user->employee_code,
            ]),
        ];

        if ($detailed) {
            $payload['total_checkpoint'] = $schedule->route?->routeCheckpoints()->count() ?? 0;
        }

        return $payload;
    }
}
