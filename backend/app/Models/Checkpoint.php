<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Checkpoint extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'area_id', 'code', 'name', 'description',
        'latitude', 'longitude', 'radius_meter', 'qr_token', 'status',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    protected static function booted(): void
    {
        static::creating(function (Checkpoint $checkpoint) {
            if (blank($checkpoint->qr_token)) {
                // Cryptographically secure, hard to guess. Format: PATROL:{code}:{random}
                $checkpoint->qr_token = 'PATROL:' . $checkpoint->code . ':' . Str::random(32);
            }
        });
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function routeCheckpoints(): HasMany
    {
        return $this->hasMany(RouteCheckpoint::class);
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(PatrolCheckin::class);
    }

    public static function generateQrToken(string $code): string
    {
        return 'PATROL:' . $code . ':' . Str::random(32);
    }
}
