<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Central audit trail. Every important mutation should go through here.
 * Records: who, what action, which entity, old vs new snapshot, IP, UA.
 */
class AuditService
{
    public function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldData = null,
        ?array $newData = null,
        ?User $actor = null,
        ?Request $request = null,
    ): AuditLog {
        $actor ??= Auth::user();
        $request ??= request();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_data' => $oldData,
            'new_data' => $newData,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    /**
     * Log a create operation.
     */
    public function created(string $entityType, int $entityId, array $newData): AuditLog
    {
        return $this->log('CREATE', $entityType, $entityId, null, $newData);
    }

    /**
     * Log an update operation with old/new snapshots.
     */
    public function updated(string $entityType, int $entityId, array $oldData, array $newData): AuditLog
    {
        return $this->log('UPDATE', $entityType, $entityId, $oldData, $newData);
    }

    /**
     * Log a delete/soft-delete operation.
     */
    public function deleted(string $entityType, int $entityId, ?array $oldData = null): AuditLog
    {
        return $this->log('DELETE', $entityType, $entityId, $oldData, null);
    }

    /**
     * Generic action log (LOGIN, LOGOUT, PATROL_START, SCAN, ...).
     */
    public function action(string $action, ?array $data = null): AuditLog
    {
        return $this->log($action, null, null, null, $data);
    }
}
