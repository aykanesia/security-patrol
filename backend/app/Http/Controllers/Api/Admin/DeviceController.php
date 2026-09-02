<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:ACTIVE,BLOCKED'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Device::query()->with('user');

        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['search'])) {
            $query->where(fn ($q) => $q
                ->where('device_name', 'like', '%' . $validated['search'] . '%')
                ->orWhere('device_uuid', 'like', '%' . $validated['search'] . '%'));
        }

        $paginated = $query->latest('last_seen_at')->paginate($validated['per_page'] ?? 15);

        return ApiResponse::success(
            collect($paginated->items())->map(fn (Device $device) => [
                'id' => $device->id,
                'user_id' => $device->user_id,
                'officer' => $device->user?->name,
                'device_uuid' => $device->device_uuid,
                'device_name' => $device->device_name,
                'platform' => $device->platform,
                'app_version' => $device->app_version,
                'last_latitude' => $device->last_latitude !== null ? (float) $device->last_latitude : null,
                'last_longitude' => $device->last_longitude !== null ? (float) $device->last_longitude : null,
                'last_seen_at' => $device->last_seen_at?->format('Y-m-d H:i:s'),
                'status' => $device->status,
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

    /**
     * PATCH /admin/devices/{id}/block | /unblock
     */
    public function setStatus(Request $request, int $id, string $action)
    {
        $device = Device::findOrFail($id);

        $status = $action === 'block' ? 'BLOCKED' : ($action === 'unblock' ? 'ACTIVE' : null);
        if (! $status) {
            return ApiResponse::error('Aksi tidak dikenal', 'INVALID_ACTION', 422);
        }

        $old = $device->status;
        $device->update(['status' => $status]);

        $this->audit->updated('Device', $device->id, ['status' => $old], ['status' => $status]);

        return ApiResponse::success([
            'id' => $device->id,
            'device_uuid' => $device->device_uuid,
            'status' => $device->status,
        ], 'Status perangkat diperbarui');
    }
}
