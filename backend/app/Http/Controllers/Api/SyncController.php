<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\SyncService;
use Illuminate\Http\Request;

/**
 * POST /sync — offline check-in batch from the Android app.
 */
class SyncController extends Controller
{
    public function __construct(private readonly SyncService $sync) {}

    public function sync(Request $request)
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:500'],
            'items.*.uuid' => ['required', 'uuid'],
            'items.*.session_code' => ['required', 'string', 'max:50'],
            'items.*.checkpoint_code' => ['required', 'string', 'max:255'],
            'items.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'items.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'items.*.gps_accuracy' => ['nullable', 'numeric', 'min:0'],
            'items.*.device_timestamp' => ['nullable', 'date'],
            'items.*.device_uuid' => ['nullable', 'string', 'max:150'],
        ]);

        $result = $this->sync->sync($request->user(), $validated['items']);

        return ApiResponse::success([
            'summary' => $result['summary'],
            'items' => $result['items'],
        ], 'Sinkronisasi selesai');
    }
}
