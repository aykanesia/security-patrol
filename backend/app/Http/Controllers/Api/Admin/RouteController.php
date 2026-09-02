<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Checkpoint;
use App\Models\PatrolRoute;
use App\Models\RouteCheckpoint;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RouteController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PatrolRoute::query()
            ->withCount('routeCheckpoints')
            ->with('area');

        if (! empty($validated['area_id'])) {
            $query->where('area_id', $validated['area_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['search'])) {
            $query->where('name', 'like', '%' . $validated['search'] . '%');
        }

        $paginated = $query->orderBy('name')->paginate($validated['per_page'] ?? 15);

        return ApiResponse::success(
            collect($paginated->items())->map(fn ($r) => $this->payload($r))->all(),
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
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'route_type' => ['nullable', 'string', 'in:SEQUENTIAL,FLEXIBLE'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE'],
            'checkpoints' => ['nullable', 'array', 'min:1'],
            'checkpoints.*.checkpoint_id' => ['required', 'integer', 'exists:checkpoints,id'],
            'checkpoints.*.sequence' => ['required', 'integer', 'min:1'],
            'checkpoints.*.is_required' => ['nullable', 'boolean'],
        ]);

        // Validate checkpoint ids are unique
        $ids = collect($validated['checkpoints'] ?? [])->pluck('checkpoint_id');
        if ($ids->count() !== $ids->unique()->count()) {
            return ApiResponse::error('Checkpoint duplikat dalam rute', 'DUPLICATE_CHECKPOINT', 422);
        }

        $route = DB::transaction(function () use ($validated) {
            $route = PatrolRoute::create([
                'area_id' => $validated['area_id'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'route_type' => $validated['route_type'] ?? 'SEQUENTIAL',
                'status' => $validated['status'] ?? 'ACTIVE',
            ]);

            foreach ($validated['checkpoints'] ?? [] as $cp) {
                RouteCheckpoint::create([
                    'route_id' => $route->id,
                    'checkpoint_id' => $cp['checkpoint_id'],
                    'sequence' => $cp['sequence'],
                    'is_required' => $cp['is_required'] ?? true,
                ]);
            }

            return $route;
        });

        $this->audit->created('PatrolRoute', $route->id, $route->only([
            'area_id', 'name', 'route_type', 'status',
        ]));

        return ApiResponse::created($this->payload($route->load('area'), true), 'Rute berhasil dibuat');
    }

    public function show(int $id)
    {
        $route = PatrolRoute::with(['area', 'routeCheckpoints.checkpoint.area'])->findOrFail($id);

        return ApiResponse::success($this->payload($route, true));
    }

    public function update(Request $request, int $id)
    {
        $route = PatrolRoute::findOrFail($id);

        $validated = $request->validate([
            'area_id' => ['sometimes', 'integer', 'exists:areas,id'],
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
            'route_type' => ['sometimes', 'string', 'in:SEQUENTIAL,FLEXIBLE'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,INACTIVE'],
            'checkpoints' => ['sometimes', 'array'],
            'checkpoints.*.checkpoint_id' => ['required_with:checkpoints', 'integer', 'exists:checkpoints,id'],
            'checkpoints.*.sequence' => ['required_with:checkpoints', 'integer', 'min:1'],
            'checkpoints.*.is_required' => ['nullable', 'boolean'],
        ]);

        $old = $route->only(['area_id', 'name', 'route_type', 'status']);

        $route = DB::transaction(function () use ($route, $validated) {
            $route->update(collect($validated)->except('checkpoints')->all());

            // Replace checkpoint set if provided
            if (array_key_exists('checkpoints', $validated)) {
                $ids = collect($validated['checkpoints'])->pluck('checkpoint_id');
                if ($ids->count() !== $ids->unique()->count()) {
                    throw new \App\Exceptions\PatrolException(
                        'Checkpoint duplikat dalam rute',
                        'DUPLICATE_CHECKPOINT',
                        422,
                    );
                }

                $route->routeCheckpoints()->delete();

                foreach ($validated['checkpoints'] as $cp) {
                    RouteCheckpoint::create([
                        'route_id' => $route->id,
                        'checkpoint_id' => $cp['checkpoint_id'],
                        'sequence' => $cp['sequence'],
                        'is_required' => $cp['is_required'] ?? true,
                    ]);
                }
            }

            return $route;
        });

        $this->audit->updated('PatrolRoute', $route->id, $old, $route->only([
            'area_id', 'name', 'route_type', 'status',
        ]));

        return ApiResponse::success($this->payload($route->fresh(['area', 'routeCheckpoints.checkpoint']), true), 'Rute berhasil diperbarui');
    }

    public function destroy(int $id)
    {
        $route = PatrolRoute::findOrFail($id);

        if ($route->schedules()->exists()) {
            return ApiResponse::error('Rute masih dipakai jadwal, tidak dapat dihapus', 'ROUTE_IN_USE', 422);
        }

        // purge pivot composition (route stays soft-deleted so session history intact)
        $route->routeCheckpoints()->delete();
        $route->delete();
        $this->audit->deleted('PatrolRoute', $id, ['name' => $route->name]);

        return ApiResponse::success(null, 'Rute berhasil dihapus');
    }

    private function payload(PatrolRoute $route, bool $detailed = false): array
    {
        $payload = [
            'id' => $route->id,
            'area_id' => $route->area_id,
            'area' => $route->area?->name,
            'name' => $route->name,
            'description' => $route->description,
            'route_type' => $route->route_type,
            'status' => $route->status,
            'checkpoints_count' => $route->route_checkpoints_count ?? $route->routeCheckpoints()->count(),
            'created_at' => $route->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($detailed) {
            $payload['checkpoints'] = $route->routeCheckpoints->map(fn (RouteCheckpoint $rc) => [
                'route_checkpoint_id' => $rc->id,
                'checkpoint_id' => $rc->checkpoint_id,
                'code' => $rc->checkpoint->code,
                'name' => $rc->checkpoint->name,
                'latitude' => (float) $rc->checkpoint->latitude,
                'longitude' => (float) $rc->checkpoint->longitude,
                'radius_meter' => (int) $rc->checkpoint->radius_meter,
                'sequence' => $rc->sequence,
                'is_required' => (bool) $rc->is_required,
            ]);
        }

        return $payload;
    }
}
