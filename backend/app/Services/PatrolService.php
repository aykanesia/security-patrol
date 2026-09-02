<?php

namespace App\Services;

use App\Enums\CheckinSyncStatus;
use App\Enums\CheckinValidationStatus;
use App\Enums\PatrolSessionStatus;
use App\Enums\RoleName;
use App\Exceptions\PatrolException;
use App\Models\Checkpoint;
use App\Models\Device;
use App\Models\PatrolCheckin;
use App\Models\PatrolSchedule;
use App\Models\PatrolSession;
use App\Models\RouteCheckpoint;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Core patrol domain logic. Controllers stay thin — all business rules
 * (schedule window, GPS radius, sequence, duplicates, idempotency)
 * live here and inside GpsValidationService.
 */
class PatrolService
{
    public function __construct(
        private readonly GpsValidationService $gps,
        private readonly AuditService $audit,
    ) {}

    // ================================================================ START

    public function startPatrol(User $user, array $payload): PatrolSession
    {
        $schedule = PatrolSchedule::query()
            ->with(['route', 'assignments'])
            ->where('id', $payload['schedule_id'])
            ->first();

        if (! $schedule || $schedule->status !== 'ACTIVE') {
            throw new PatrolException('Jadwal patroli tidak ditemukan', 'SCHEDULE_NOT_FOUND', 404);
        }
        if (! $schedule->route || $schedule->route->status !== 'ACTIVE') {
            throw new PatrolException('Rute patroli tidak aktif', 'ROUTE_INACTIVE', 422);
        }
        if ($user->status !== 'ACTIVE') {
            throw new PatrolException('Akun anda tidak aktif', 'USER_INACTIVE', 403);
        }
        if (! $user->hasRole(RoleName::SECURITY)) {
            throw new PatrolException('Hanya petugas security yang dapat memulai patroli', 'FORBIDDEN_ROLE', 403);
        }
        $this->assertUserAssignedToSchedule($schedule, $user);
        $this->assertScheduleMatchesNow($schedule);
        $this->assertNoRunningSession($user);

        $device = $this->resolveDevice($user, $payload['device_uuid'] ?? null);

        $totalCheckpoints = $schedule->route->routeCheckpoints()->count();
        if ($totalCheckpoints === 0) {
            throw new PatrolException('Rute tidak memiliki checkpoint', 'ROUTE_EMPTY', 422);
        }

        $session = DB::transaction(function () use ($user, $schedule, $device, $payload, $totalCheckpoints) {
            $now = now();

            $session = PatrolSession::create([
                'uuid' => (string) Str::uuid(),
                'session_code' => PatrolSession::generateSessionCode(),
                'schedule_id' => $schedule->id,
                'user_id' => $user->id,
                'route_id' => $schedule->route_id,
                'device_id' => $device?->id,
                'started_at' => $now,
                'started_latitude' => $payload['latitude'],
                'started_longitude' => $payload['longitude'],
                'status' => PatrolSessionStatus::RUNNING->value,
                'total_checkpoint' => $totalCheckpoints,
                'completed_checkpoint' => 0,
            ]);

            $this->audit->log('PATROL_START', PatrolSession::class, $session->id, null, [
                'session_code' => $session->session_code,
                'schedule_id' => $schedule->id,
                'user_id' => $user->id,
            ]);

            return $session;
        });

        return $session->load(['route', 'schedule', 'user.role']);
    }

    // ============================================================== CURRENT

    public function getCurrentPatrol(User $user): ?array
    {
        $session = $this->findRunningSession($user);
        if (! $session) {
            return null;
        }

        return $this->buildCurrentPatrolPayload($session);
    }

    // ================================================================= SCAN

    /**
     * @return array{patrol_checkin: PatrolCheckin, session: PatrolSession}
     */
    public function scanCheckpoint(User $user, array $payload): array
    {
        $session = PatrolSession::query()
            ->where('session_code', $payload['session_code'])
            ->first();

        if (! $session) {
            throw new PatrolException('Sesi patroli tidak ditemukan', 'SESSION_NOT_FOUND', 404);
        }
        if ($session->user_id !== $user->id) {
            throw new PatrolException('Sesi patroli bukan milik anda', 'INVALID_SESSION', 403);
        }
        if (! $session->isRunning()) {
            throw new PatrolException('Sesi patroli tidak sedang berjalan', 'SESSION_NOT_RUNNING', 422);
        }

        $checkpoint = $this->resolveCheckpoint($payload['scan_code'] ?? '');
        $device = $this->resolveDevice($user, $payload['device_uuid'] ?? null, false);

        // Idempotency: same request UUID must never create two records.
        if (! empty($payload['uuid'])) {
            $existing = PatrolCheckin::where('uuid', $payload['uuid'])->first();
            if ($existing) {
                throw new PatrolException(
                    'Checkpoint sudah diproses sebelumnya',
                    'ALREADY_PROCESSED',
                    200,
                    ['checkin_uuid' => $existing->uuid, 'validation_status' => $existing->validation_status],
                );
            }
        }

        try {
            $result = $this->processCheckpointAttempt($session, $checkpoint, $device, $payload, $user, $payload['uuid'] ?? null);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // concurrency race on the same uuid — unique constraint is the backstop
            throw new PatrolException(
                'Checkpoint sudah diproses sebelumnya',
                'ALREADY_PROCESSED',
                200,
                ['checkin_uuid' => $payload['uuid'] ?? null],
            );
        }

        $this->audit->log('CHECKPOINT_SCAN', PatrolCheckin::class, $result['patrol_checkin']->id, null, [
            'session_code' => $session->session_code,
            'checkpoint_code' => $checkpoint->code,
            'validation_status' => $result['patrol_checkin']->validation_status,
        ]);

        return $result;
    }

    /**
     * Pure validation + persistence of one checkpoint attempt. Shared by the
     * online scan and the offline sync endpoints.
     *
     * @return array{patrol_checkin: PatrolCheckin, session: PatrolSession}
     */
    public function processCheckpointAttempt(
        PatrolSession $session,
        Checkpoint $checkpoint,
        ?Device $device,
        array $payload,
        User $user,
        ?string $uuid = null,
    ): array {
        // -- checkpoint belongs to the session route?
        $routeCheckpoint = RouteCheckpoint::where('route_id', $session->route_id)
            ->where('checkpoint_id', $checkpoint->id)
            ->first();

        if (! $routeCheckpoint) {
            $checkin = $this->recordAttempt($session, $checkpoint, $device, $payload, $uuid, CheckinValidationStatus::INVALID_CHECKPOINT);
            throw new PatrolException('Checkpoint tidak terdaftar pada rute ini', 'INVALID_CHECKPOINT', 422, [
                'checkpoint' => $checkpoint->code,
            ]);
        }

        if ($checkpoint->status !== 'ACTIVE') {
            $checkin = $this->recordAttempt($session, $checkpoint, $device, $payload, $uuid, CheckinValidationStatus::INVALID_CHECKPOINT);
            throw new PatrolException('Checkpoint tidak aktif', 'INVALID_CHECKPOINT', 422);
        }

        // -- duplicate (already visited VALID in this session)
        $alreadyVisited = PatrolCheckin::where('session_id', $session->id)
            ->where('checkpoint_id', $checkpoint->id)
            ->where('validation_status', CheckinValidationStatus::VALID->value)
            ->exists();

        if ($alreadyVisited) {
            $checkin = $this->recordAttempt($session, $checkpoint, $device, $payload, $uuid, CheckinValidationStatus::DUPLICATE);
            throw new PatrolException('Checkpoint sudah discan sebelumnya', 'DUPLICATE_CHECKIN', 422, [
                'checkpoint' => $checkpoint->code,
            ]);
        }

        // -- sequence rule (SEQUENTIAL routes only)
        if ($session->route->isSequential()) {
            $next = $this->nextExpectedCheckpoint($session);
            if ($next && $next->checkpoint_id !== $checkpoint->id) {
                $checkin = $this->recordAttempt($session, $checkpoint, $device, $payload, $uuid, CheckinValidationStatus::INVALID_SEQUENCE);
                $required = Checkpoint::find($next->checkpoint_id);
                throw new PatrolException('Checkpoint belum dapat dikunjungi, ikuti urutan rute', 'INVALID_SEQUENCE', 422, [
                    'required_checkpoint' => $required?->code,
                ]);
            }
        }

        // -- GPS radius (backend-calculated, never trust client distance)
        $location = $this->gps->validateCheckpointLocation(
            (float) $checkpoint->latitude,
            (float) $checkpoint->longitude,
            (float) $payload['latitude'],
            (float) $payload['longitude'],
            (float) $checkpoint->radius_meter,
        );

        if (! $location['valid']) {
            $checkin = $this->recordAttempt($session, $checkpoint, $device, $payload, $uuid, CheckinValidationStatus::INVALID_LOCATION);
            throw new PatrolException('Anda berada di luar radius checkpoint', 'INVALID_LOCATION', 422, [
                'distance_meter' => $location['distance_meter'],
                'allowed_radius' => (int) $checkpoint->radius_meter,
            ]);
        }

        // -- all good: VALID checkin (atomic: insert + progress refresh)
        $checkin = DB::transaction(function () use ($session, $checkpoint, $device, $payload, $location, $uuid) {
            $checkin = PatrolCheckin::create([
                'uuid' => $uuid ?? (string) Str::uuid(),
                'session_id' => $session->id,
                'checkpoint_id' => $checkpoint->id,
                'device_id' => $device?->id,
                'scan_code' => $checkpoint->code,
                'scanned_at' => now(),
                'device_timestamp' => $payload['device_timestamp'] ?? null,
                'latitude' => $payload['latitude'],
                'longitude' => $payload['longitude'],
                'distance_meter' => $location['distance_meter'],
                'gps_accuracy' => $payload['gps_accuracy'] ?? null,
                'validation_status' => CheckinValidationStatus::VALID->value,
                'sync_status' => CheckinSyncStatus::SYNCED->value,
            ]);

            $this->refreshSessionProgress($session);

            return $checkin;
        });

        return ['patrol_checkin' => $checkin, 'session' => $session->fresh()];
    }

    // ============================================================== COMPLETE

    public function completePatrol(User $user, array $payload): PatrolSession
    {
        $session = $this->findSessionForUser($user, $payload['session_code']);

        if (! $session->isRunning()) {
            throw new PatrolException('Sesi patroli tidak sedang berjalan', 'SESSION_NOT_RUNNING', 422);
        }

        $requiredTotal = RouteCheckpoint::where('route_id', $session->route_id)
            ->where('is_required', true)
            ->count();

        $validCount = $session->validCheckins()->count();

        if ($validCount < $requiredTotal) {
            throw new PatrolException(
                'Patroli belum lengkap, masih ada checkpoint yang harus dikunjungi',
                'CHECKPOINT_INCOMPLETE',
                422,
                [
                    'completed' => $validCount,
                    'total' => $session->total_checkpoint,
                    'remaining' => $requiredTotal - $validCount,
                ],
            );
        }

        $now = now();

        $session->forceFill([
            'status' => PatrolSessionStatus::COMPLETED->value,
            'completed_at' => $now,
            'completed_latitude' => $payload['latitude'] ?? $session->completed_latitude,
            'completed_longitude' => $payload['longitude'] ?? $session->completed_longitude,
            'completed_checkpoint' => $validCount,
        ])->save();

        $this->audit->log('PATROL_COMPLETE', PatrolSession::class, $session->id, null, [
            'session_code' => $session->session_code,
            'duration_seconds' => $session->durationSeconds(),
        ]);

        return $session->fresh(['route', 'schedule', 'user.role']);
    }

    // =============================================================== CANCEL

    public function cancelPatrol(User $user, array $payload): PatrolSession
    {
        $session = $this->findSessionForUser($user, $payload['session_code']);

        if (! $session->isRunning()) {
            throw new PatrolException('Sesi patroli tidak sedang berjalan', 'SESSION_NOT_RUNNING', 422);
        }

        $session->forceFill([
            'status' => PatrolSessionStatus::CANCELLED->value,
            'completed_at' => now(),
        ])->save();

        $this->audit->log('PATROL_CANCEL', PatrolSession::class, $session->id, null, [
            'session_code' => $session->session_code,
            'reason' => $payload['reason'] ?? null,
        ]);

        return $session->fresh(['route', 'schedule', 'user.role']);
    }

    // =============================================================== HISTORY

    public function getPatrolHistory(User $user, array $filters = []): Builder
    {
        $query = PatrolSession::query()
            ->with(['route', 'schedule', 'user.role'])
            ->where('user_id', $user->id)
            ->orderByDesc('started_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('started_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('started_at', '<=', $filters['to']);
        }

        return $query;
    }

    // =============================================================== DETAIL

    public function getPatrolDetail(User $user, string $sessionCode): PatrolSession
    {
        $session = PatrolSession::query()
            ->with([
                'route.area',
                'schedule',
                'user.role',
                'device',
                'checkins.checkpoint.area',
            ])
            ->where('session_code', $sessionCode)
            ->firstOrFail();

        if ($user->hasRole(RoleName::SECURITY) && $session->user_id !== $user->id) {
            throw new PatrolException('Sesi patroli bukan milik anda', 'FORBIDDEN', 403);
        }

        return $session;
    }

    // ========================================================== SUPERVISOR

    public function markIncomplete(User $actor, string $sessionCode, ?string $reason): PatrolSession
    {
        $session = PatrolSession::where('session_code', $sessionCode)->firstOrFail();

        if ($session->status !== PatrolSessionStatus::RUNNING->value) {
            throw new PatrolException('Hanya sesi RUNNING yang dapat ditandai incomplete', 'INVALID_STATUS', 422);
        }

        $session->forceFill([
            'status' => PatrolSessionStatus::INCOMPLETE->value,
            'completed_at' => now(),
        ])->save();

        $this->audit->log('PATROL_INCOMPLETE', PatrolSession::class, $session->id, null, [
            'session_code' => $session->session_code,
            'reason' => $reason,
            'acted_by' => $actor->id,
        ]);

        return $session->fresh(['route', 'schedule', 'user.role']);
    }

    // ==================================================== INTERNAL HELPERS

    private function findRunningSession(User $user): ?PatrolSession
    {
        return PatrolSession::query()
            ->with(['route.area', 'schedule', 'user.role'])
            ->where('user_id', $user->id)
            ->where('status', PatrolSessionStatus::RUNNING->value)
            ->latest('started_at')
            ->first();
    }

    private function findSessionForUser(User $user, string $sessionCode): PatrolSession
    {
        $session = PatrolSession::where('session_code', $sessionCode)->first();

        if (! $session) {
            throw new PatrolException('Sesi patroli tidak ditemukan', 'SESSION_NOT_FOUND', 404);
        }
        if ($session->user_id !== $user->id) {
            throw new PatrolException('Sesi patroli bukan milik anda', 'FORBIDDEN', 403);
        }

        return $session;
    }

    private function assertUserAssignedToSchedule(PatrolSchedule $schedule, User $user): void
    {
        $assigned = $schedule->assignments()->where('user_id', $user->id)->exists();

        if (! $assigned) {
            throw new PatrolException('Anda tidak terdaftar pada jadwal ini', 'NOT_ASSIGNED', 403);
        }
    }

    private function assertScheduleMatchesNow(PatrolSchedule $schedule): void
    {
        $now = now();
        $dayOfWeek = (int) $now->format('w'); // 0=Sunday .. 6=Saturday

        if (! $schedule->isActiveOnDay($dayOfWeek)) {
            throw new PatrolException('Jadwal tidak berlaku untuk hari ini', 'SCHEDULE_DAY_MISMATCH', 422);
        }

        $start = Carbon::parse($schedule->start_time);
        $end = Carbon::parse($schedule->end_time);

        // handle overnight schedules (end < start => next day)
        if ($end->lessThan($start)) {
            $end = $end->addDay();
        }

        $windowStart = $start->copy()->subMinutes($schedule->grace_before_minutes);
        $windowEnd = $end->copy()->addMinutes($schedule->grace_after_minutes);

        $nowTime = $now->copy()->setDateFrom($start); // normalize to same date basis

        // If the schedule is overnight and we're before the window on the same
        // day, the relevant window may have started the previous day.
        if ($schedule->day_of_week !== null) {
            $nowTime = $now->copy();
            $candidate = $start->copy()->setDateFrom($now);

            if ($now->lessThan($windowStart->setDateFrom($now))) {
                // check previous-day window (overnight)
                $prevStart = $start->copy()->setDateFrom($now->copy()->subDay());
                $prevEnd = $end->copy()->setDateFrom($now->copy()->subDay());
                if ($prevEnd->lessThan($prevStart)) {
                    $prevEnd = $prevEnd->addDay();
                }
                $prevWindowStart = $prevStart->copy()->subMinutes($schedule->grace_before_minutes);
                $prevWindowEnd = $prevEnd->copy()->addMinutes($schedule->grace_after_minutes);
                if ($now->between($prevWindowStart, $prevWindowEnd)) {
                    return;
                }
            }
        }

        // Normalize window dates to today for comparison
        $windowStartDay = $windowStart->copy()->setDateFrom($now);
        $windowEndDay = $windowEnd->copy()->setDateFrom($now);

        // if window crosses midnight, allow until windowEnd on the next day
        if ($windowEndDay->lessThan($windowStartDay)) {
            $windowEndDay = $windowEndDay->addDay();
        }

        if ($now->lessThan($windowStartDay)) {
            throw new PatrolException('Belum waktunya memulai patroli', 'SCHEDULE_TOO_EARLY', 422, [
                'window_start' => $windowStartDay->format('Y-m-d H:i:s'),
            ]);
        }

        if ($now->greaterThan($windowEndDay)) {
            throw new PatrolException('Jadwal patroli sudah berakhir', 'SCHEDULE_TOO_LATE', 422, [
                'window_end' => $windowEndDay->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function assertNoRunningSession(User $user): void
    {
        $running = PatrolSession::where('user_id', $user->id)
            ->where('status', PatrolSessionStatus::RUNNING->value)
            ->exists();

        if ($running) {
            throw new PatrolException('Anda masih memiliki patroli yang berjalan', 'SESSION_ALREADY_RUNNING', 422);
        }
    }

    private function resolveDevice(User $user, ?string $deviceUuid, bool $required = true): ?Device
    {
        if (blank($deviceUuid)) {
            if ($required) {
                throw new PatrolException('Device UUID wajib diisi', 'DEVICE_REQUIRED', 422);
            }

            return null;
        }

        $device = Device::where('device_uuid', $deviceUuid)->first();

        if (! $device || $device->user_id !== $user->id) {
            throw new PatrolException('Perangkat tidak terdaftar', 'DEVICE_NOT_FOUND', 403);
        }
        if ($device->status !== 'ACTIVE') {
            throw new PatrolException('Perangkat diblokir', 'DEVICE_BLOCKED', 403);
        }

        return $device;
    }

    private function resolveCheckpoint(string $scanCode): Checkpoint
    {
        // Accept either the public code (CP001) or the full QR token
        // (PATROL:CP001:<random>). The token variant is what a real scan sends.
        if (str_contains($scanCode, 'PATROL:')) {
            $checkpoint = Checkpoint::where('qr_token', $scanCode)->first();
        } else {
            $checkpoint = Checkpoint::where('code', $scanCode)->first();
        }

        if (! $checkpoint) {
            throw new PatrolException('QR Code tidak dikenali', 'INVALID_CHECKPOINT', 422, [
                'checkpoint' => $scanCode,
            ]);
        }

        return $checkpoint;
    }

    private function recordAttempt(
        PatrolSession $session,
        Checkpoint $checkpoint,
        ?Device $device,
        array $payload,
        ?string $uuid,
        CheckinValidationStatus $status,
    ): PatrolCheckin {
        $location = $this->gps->validateCheckpointLocation(
            (float) $checkpoint->latitude,
            (float) $checkpoint->longitude,
            (float) ($payload['latitude'] ?? 0),
            (float) ($payload['longitude'] ?? 0),
            (float) $checkpoint->radius_meter,
        );

        return PatrolCheckin::create([
            'uuid' => $uuid ?? (string) Str::uuid(),
            'session_id' => $session->id,
            'checkpoint_id' => $checkpoint->id,
            'device_id' => $device?->id,
            'scan_code' => $checkpoint->code,
            'scanned_at' => now(),
            'device_timestamp' => $payload['device_timestamp'] ?? null,
            'latitude' => $payload['latitude'] ?? 0,
            'longitude' => $payload['longitude'] ?? 0,
            'distance_meter' => $location['distance_meter'],
            'gps_accuracy' => $payload['gps_accuracy'] ?? null,
            'validation_status' => $status->value,
            'sync_status' => CheckinSyncStatus::FAILED->value,
        ]);
    }

    /**
     * Recompute completed count from VALID check-ins (source of truth),
     * then persist on the session.
     */
    private function refreshSessionProgress(PatrolSession $session): void
    {
        $validCount = PatrolCheckin::where('session_id', $session->id)
            ->where('validation_status', CheckinValidationStatus::VALID->value)
            ->count();

        $session->forceFill(['completed_checkpoint' => $validCount])->save();
    }

    private function nextExpectedCheckpoint(PatrolSession $session): ?RouteCheckpoint
    {
        $visitedIds = PatrolCheckin::where('session_id', $session->id)
            ->where('validation_status', CheckinValidationStatus::VALID->value)
            ->pluck('checkpoint_id');

        return RouteCheckpoint::where('route_id', $session->route_id)
            ->whereNotIn('checkpoint_id', $visitedIds)
            ->orderBy('sequence')
            ->first();
    }

    private function buildCurrentPatrolPayload(PatrolSession $session): array
    {
        $visited = PatrolCheckin::where('session_id', $session->id)
            ->where('validation_status', CheckinValidationStatus::VALID->value)
            ->pluck('checkpoint_id')
            ->all();

        $checkpoints = RouteCheckpoint::with('checkpoint')
            ->where('route_id', $session->route_id)
            ->orderBy('sequence')
            ->get()
            ->map(fn (RouteCheckpoint $rc) => [
                'id' => $rc->checkpoint->id,
                'code' => $rc->checkpoint->code,
                'name' => $rc->checkpoint->name,
                'latitude' => (float) $rc->checkpoint->latitude,
                'longitude' => (float) $rc->checkpoint->longitude,
                'sequence' => $rc->sequence,
                'is_required' => (bool) $rc->is_required,
                'status' => in_array($rc->checkpoint_id, $visited, true) ? 'COMPLETED' : 'PENDING',
            ]);

        return [
            'session' => [
                'id' => $session->id,
                'session_code' => $session->session_code,
                'uuid' => $session->uuid,
                'status' => $session->status,
                'started_at' => $session->started_at?->format('Y-m-d H:i:s'),
                'total_checkpoint' => $session->total_checkpoint,
                'completed_checkpoint' => $session->completed_checkpoint,
            ],
            'route' => [
                'id' => $session->route->id,
                'name' => $session->route->name,
                'route_type' => $session->route->route_type,
                'area' => $session->route->area?->name,
            ],
            'schedule' => $session->schedule ? [
                'id' => $session->schedule->id,
                'name' => $session->schedule->name,
                'start_time' => $session->schedule->start_time,
                'end_time' => $session->schedule->end_time,
            ] : null,
            'checkpoints' => $checkpoints,
            'progress_percentage' => $session->progressPercentage(),
        ];
    }
}
