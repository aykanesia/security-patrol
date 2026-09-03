<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Checkpoint;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckpointController extends Controller
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

        $query = Checkpoint::query()->with('area');

        if (! empty($validated['area_id'])) {
            $query->where('area_id', $validated['area_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('code', 'like', '%' . $validated['search'] . '%')
                    ->orWhere('name', 'like', '%' . $validated['search'] . '%');
            });
        }

        // `all` dari query string tiba sebagai string "true"/"false" (bukan bool),
        // sehingga aturan 'boolean' menolaknya (422). $request->boolean() menormalkan.
        if ($request->boolean('all')) {
            return ApiResponse::success($query->orderBy('code')->get()->map(fn ($c) => $this->payload($c)));
        }

        $paginated = $query->orderBy('code')->paginate($validated['per_page'] ?? 15);

        return ApiResponse::success(
            collect($paginated->items())->map(fn ($c) => $this->payload($c))->all(),
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
            'code' => ['required', 'string', 'max:50', 'unique:checkpoints,code'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meter' => ['nullable', 'integer', 'min:5', 'max:5000'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE'],
        ]);

        $checkpoint = Checkpoint::create([
            'area_id' => $validated['area_id'],
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'radius_meter' => $validated['radius_meter'] ?? 30,
            'status' => $validated['status'] ?? 'ACTIVE',
            // qr_token auto-generated in model boot
        ]);

        $this->audit->created('Checkpoint', $checkpoint->id, $checkpoint->only([
            'area_id', 'code', 'name', 'latitude', 'longitude', 'radius_meter', 'status',
        ]));

        return ApiResponse::created($this->payload($checkpoint), 'Checkpoint berhasil dibuat');
    }

    public function show(int $id)
    {
        $checkpoint = Checkpoint::with('area')->findOrFail($id);

        return ApiResponse::success($this->payload($checkpoint, true));
    }

    public function update(Request $request, int $id)
    {
        $checkpoint = Checkpoint::findOrFail($id);

        $validated = $request->validate([
            'area_id' => ['sometimes', 'integer', 'exists:areas,id'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('checkpoints', 'code')->ignore($checkpoint->id)],
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'radius_meter' => ['sometimes', 'integer', 'min:5', 'max:5000'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,INACTIVE'],
        ]);

        $old = $checkpoint->only([
            'area_id', 'code', 'name', 'latitude', 'longitude', 'radius_meter', 'status',
        ]);

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        $checkpoint->update($validated);

        $this->audit->updated('Checkpoint', $checkpoint->id, $old, $checkpoint->only([
            'area_id', 'code', 'name', 'latitude', 'longitude', 'radius_meter', 'status',
        ]));

        return ApiResponse::success($this->payload($checkpoint), 'Checkpoint berhasil diperbarui');
    }

    public function destroy(int $id)
    {
        $checkpoint = Checkpoint::findOrFail($id);

        if ($checkpoint->routeCheckpoints()->exists()) {
            return ApiResponse::error('Checkpoint masih dipakai rute, tidak dapat dihapus', 'CHECKPOINT_IN_USE', 422);
        }

        $checkpoint->delete();
        $this->audit->deleted('Checkpoint', $id, ['code' => $checkpoint->code, 'name' => $checkpoint->name]);

        return ApiResponse::success(null, 'Checkpoint berhasil dihapus');
    }

    /**
     * GET /admin/checkpoints/{id}/qr — printable QR payload for the checkpoint.
     */
    public function qr(int $id)
    {
        $checkpoint = Checkpoint::findOrFail($id);

        return ApiResponse::success([
            'id' => $checkpoint->id,
            'code' => $checkpoint->code,
            'name' => $checkpoint->name,
            'qr_token' => $checkpoint->qr_token, // full token to embed into QR image
        ]);
    }

    private function payload(Checkpoint $checkpoint, bool $detailed = false): array
    {
        $payload = [
            'id' => $checkpoint->id,
            'area_id' => $checkpoint->area_id,
            'area' => $checkpoint->area?->name,
            'code' => $checkpoint->code,
            'name' => $checkpoint->name,
            'description' => $checkpoint->description,
            'latitude' => (float) $checkpoint->latitude,
            'longitude' => (float) $checkpoint->longitude,
            'radius_meter' => (int) $checkpoint->radius_meter,
            'status' => $checkpoint->status,
            'created_at' => $checkpoint->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($detailed) {
            $payload['qr_token'] = $checkpoint->qr_token;
        }

        return $payload;
    }
}
