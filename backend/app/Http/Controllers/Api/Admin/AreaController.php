<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Area;
use App\Services\AuditService;
use Illuminate\Http\Request;

class AreaController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Area::query();

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['search'])) {
            $query->where('name', 'like', '%' . $validated['search'] . '%');
        }

        // `all` di query string tiba sebagai string "true"/"false" (bukan bool),
        // sehingga aturan 'boolean' Laravel menolaknya (422). Pakai $request->boolean()
        // yang menormalisasi "true"/"1"/1 → true dan "false"/"0" → false.
        if ($request->boolean('all')) {
            $items = $query->withCount(['checkpoints', 'routes'])->orderBy('name')->get();

            return ApiResponse::success($items->map(fn (Area $a) => $this->payload($a)));
        }

        $paginated = $query->withCount(['checkpoints', 'routes'])->orderBy('name')
            ->paginate($validated['per_page'] ?? 15);

        return ApiResponse::success(
            collect($paginated->items())->map(fn (Area $a) => $this->payload($a))->all(),
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
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE'],
        ]);

        $area = Area::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'ACTIVE',
        ]);

        $this->audit->created('Area', $area->id, $area->only(['name', 'description', 'status']));

        return ApiResponse::created($this->payload($area), 'Area berhasil dibuat');
    }

    public function show(int $id)
    {
        $area = Area::with(['checkpoints', 'routes'])->findOrFail($id);

        return ApiResponse::success($this->payload($area, true));
    }

    public function update(Request $request, int $id)
    {
        $area = Area::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,INACTIVE'],
        ]);

        $old = $area->only(['name', 'description', 'status']);
        $area->update($validated);

        $this->audit->updated('Area', $area->id, $old, $area->only(['name', 'description', 'status']));

        return ApiResponse::success($this->payload($area), 'Area berhasil diperbarui');
    }

    public function destroy(int $id)
    {
        $area = Area::findOrFail($id);

        if ($area->checkpoints()->exists() || $area->routes()->exists()) {
            return ApiResponse::error(
                'Area masih memiliki checkpoint/rute, tidak dapat dihapus',
                'AREA_IN_USE',
                422,
            );
        }

        $area->delete();
        $this->audit->deleted('Area', $id, ['name' => $area->name]);

        return ApiResponse::success(null, 'Area berhasil dihapus');
    }

    private function payload(Area $area, bool $detailed = false): array
    {
        $payload = [
            'id' => $area->id,
            'name' => $area->name,
            'description' => $area->description,
            'status' => $area->status,
            'checkpoints_count' => $area->checkpoints_count ?? $area->checkpoints()->count(),
            'routes_count' => $area->routes_count ?? $area->routes()->count(),
            'created_at' => $area->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($detailed) {
            $payload['checkpoints'] = $area->checkpoints->map(fn ($c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'status' => $c->status,
            ]);
            $payload['routes'] = $area->routes->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'route_type' => $r->route_type, 'status' => $r->status,
            ]);
        }

        return $payload;
    }
}
