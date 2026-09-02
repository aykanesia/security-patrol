<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatrolSchedule extends Model
{
    protected $fillable = [
        'route_id', 'name', 'day_of_week', 'start_time', 'end_time',
        'grace_before_minutes', 'grace_after_minutes', 'status',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(PatrolRoute::class, 'route_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PatrolScheduleAssignment::class, 'schedule_id');
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'patrol_schedule_assignments', 'schedule_id', 'user_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PatrolSession::class, 'schedule_id');
    }

    public function isActiveOnDay(?int $dayOfWeek): bool
    {
        // null day_of_week = runs every day
        return $this->day_of_week === null || $this->day_of_week === $dayOfWeek;
    }
}
