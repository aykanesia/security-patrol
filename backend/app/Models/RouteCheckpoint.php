<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteCheckpoint extends Model
{
    protected $fillable = ['route_id', 'checkpoint_id', 'sequence', 'is_required'];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(PatrolRoute::class, 'route_id');
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(Checkpoint::class);
    }
}
