<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PatrolSession extends Model
{
    protected $fillable = [
        'uuid', 'session_code', 'schedule_id', 'user_id', 'route_id', 'device_id',
        'started_at', 'started_latitude', 'started_longitude',
        'completed_at', 'completed_latitude', 'completed_longitude',
        'status', 'total_checkpoint', 'completed_checkpoint',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'started_latitude' => 'decimal:8',
        'started_longitude' => 'decimal:8',
        'completed_latitude' => 'decimal:8',
        'completed_longitude' => 'decimal:8',
    ];

    protected static function booted(): void
    {
        static::creating(function (PatrolSession $session) {
            if (blank($session->uuid)) {
                $session->uuid = (string) Str::uuid();
            }
            if (blank($session->session_code)) {
                $session->session_code = self::generateSessionCode();
            }
        });
    }

    public static function generateSessionCode(): string
    {
        $date = now()->format('Ymd');
        $last = static::query()
            ->where('session_code', 'like', "PAT-{$date}-%")
            ->orderByDesc('session_code')
            ->value('session_code');

        $seq = 1;
        if ($last) {
            $seq = ((int) substr($last, -6)) + 1;
        }

        return sprintf('PAT-%s-%06d', $date, $seq);
    }

    // ------------------------------------------------------------ relations

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PatrolSchedule::class, 'schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(PatrolRoute::class, 'route_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(PatrolCheckin::class, 'session_id');
    }

    /** Check-ins with VALID status only (the actual completed visits). */
    public function validCheckins(): HasMany
    {
        return $this->checkins()->where('validation_status', 'VALID');
    }

    // ------------------------------------------------------------ helpers

    public function isRunning(): bool
    {
        return $this->status === 'RUNNING';
    }

    public function progressPercentage(): int
    {
        if ($this->total_checkpoint <= 0) {
            return 0;
        }

        return (int) round(($this->completed_checkpoint / $this->total_checkpoint) * 100);
    }

    public function durationSeconds(): ?int
    {
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->completed_at);
    }
}
