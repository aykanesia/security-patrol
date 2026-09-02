<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PatrolCheckin extends Model
{
    protected $fillable = [
        'uuid', 'session_id', 'checkpoint_id', 'device_id', 'scan_code',
        'scanned_at', 'device_timestamp', 'latitude', 'longitude',
        'distance_meter', 'gps_accuracy', 'validation_status', 'sync_status',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'device_timestamp' => 'datetime',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'distance_meter' => 'decimal:2',
        'gps_accuracy' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (PatrolCheckin $checkin) {
            if (blank($checkin->uuid)) {
                $checkin->uuid = (string) Str::uuid();
            }
        });
    }

    // ------------------------------------------------------------ relations

    public function session(): BelongsTo
    {
        return $this->belongsTo(PatrolSession::class, 'session_id');
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    // ------------------------------------------------------------ helpers

    public function isValid(): bool
    {
        return $this->validation_status === 'VALID';
    }
}
