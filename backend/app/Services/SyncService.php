<?php

namespace App\Services;

use App\Enums\CheckinValidationStatus;
use App\Exceptions\PatrolException;
use App\Models\Checkpoint;
use App\Models\PatrolCheckin;
use App\Models\PatrolSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Offline sync for the Android app.
 *
 * The Android client queues check-ins while offline and posts them as a
 * batch. Each item carries a client-generated UUID => idempotent:
 * if the UUID already exists the backend must NOT insert again.
 */
class SyncService
{
    public function __construct(private readonly PatrolService $patrolService) {}

    public function sync(User $user, array $items): array
    {
        $summary = [
            'processed' => count($items),
            'success' => 0,
            'duplicate' => 0,
            'failed' => 0,
        ];

        $results = [];

        foreach ($items as $item) {
            $result = $this->syncItem($user, $item);
            $summary[$result['status'] === 'success' ? 'success'
                : ($result['status'] === 'duplicate' ? 'duplicate' : 'failed')]++;

            $results[] = $result;
        }

        return [
            'summary' => $summary,
            'items' => $results,
        ];
    }

    private function syncItem(User $user, array $item): array
    {
        $base = [
            'uuid' => $item['uuid'] ?? null,
            'session_code' => $item['session_code'] ?? null,
            'checkpoint_code' => $item['checkpoint_code'] ?? null,
        ];

        // -- validate required fields
        if (blank($item['uuid'] ?? null) || blank($item['session_code'] ?? null) || blank($item['checkpoint_code'] ?? null)) {
            return ['status' => 'failed', 'error_code' => 'INVALID_PAYLOAD', 'message' => 'Field wajib tidak lengkap'] + $base;
        }

        // -- idempotency: uuid already exists?
        if (PatrolCheckin::where('uuid', $item['uuid'])->exists()) {
            return ['status' => 'duplicate', 'error_code' => 'DUPLICATE', 'message' => 'Check-in sudah diproses'] + $base;
        }

        $session = PatrolSession::where('session_code', $item['session_code'])->first();
        if (! $session || $session->user_id !== $user->id) {
            return ['status' => 'failed', 'error_code' => 'INVALID_SESSION', 'message' => 'Sesi tidak valid'] + $base;
        }

        $checkpoint = Checkpoint::where('code', $item['checkpoint_code'])
            ->orWhere('qr_token', $item['checkpoint_code'])
            ->first();

        if (! $checkpoint) {
            return ['status' => 'failed', 'error_code' => 'INVALID_CHECKPOINT', 'message' => 'Checkpoint tidak dikenal'] + $base;
        }

        $device = null;
        if (! blank($item['device_uuid'] ?? null)) {
            $device = $session->device_id
                ? $session->device
                : \App\Models\Device::where('device_uuid', $item['device_uuid'])->first();
        }

        try {
            DB::beginTransaction();

            $attempt = $this->patrolService->processCheckpointAttempt(
                $session,
                $checkpoint,
                $device,
                [
                    'latitude' => $item['latitude'] ?? 0,
                    'longitude' => $item['longitude'] ?? 0,
                    'gps_accuracy' => $item['gps_accuracy'] ?? null,
                    'device_timestamp' => $item['device_timestamp'] ?? null,
                    'uuid' => $item['uuid'],
                ],
                $user,
                $item['uuid'],
            );

            DB::commit();

            $checkin = $attempt['patrol_checkin'];

            return [
                'status' => 'success',
                'error_code' => null,
                'message' => 'Check-in berhasil',
                'validation_status' => $checkin->validation_status,
                'distance_meter' => (float) $checkin->distance_meter,
                'scanned_at' => $checkin->scanned_at?->format('Y-m-d H:i:s'),
            ] + $base;
        } catch (PatrolException $e) {
            DB::rollBack();

            return [
                'status' => 'failed',
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
                'data' => $e->data,
            ] + $base;
        } catch (\Throwable $e) {
            DB::rollBack();

            return [
                'status' => 'failed',
                'error_code' => 'INTERNAL_ERROR',
                'message' => 'Kesalahan internal: ' . $e->getMessage(),
            ] + $base;
        }
    }
}
