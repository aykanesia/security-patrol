<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id', 'action', 'entity_type', 'entity_id',
        'old_data', 'new_data', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array',
        'created_at' => 'datetime',
    ];

    public const UPDATED_AT = null; // audit logs are immutable; only created_at

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
