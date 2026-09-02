<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    protected $fillable = [
        'user_id', 'device_uuid', 'device_name', 'platform', 'app_version',
        'last_latitude', 'last_longitude', 'last_seen_at', 'status',
    ];

    protected $casts = [
        'last_latitude' => 'decimal:8',
        'last_longitude' => 'decimal:8',
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
