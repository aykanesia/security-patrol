<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatrolScheduleAssignment extends Model
{
    protected $fillable = ['schedule_id', 'user_id'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PatrolSchedule::class, 'schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
