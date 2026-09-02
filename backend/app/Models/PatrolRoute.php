<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PatrolRoute extends Model
{
    use SoftDeletes;

    protected $table = 'patrol_routes';

    protected $fillable = ['area_id', 'name', 'description', 'route_type', 'status'];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function routeCheckpoints(): HasMany
    {
        return $this->hasMany(RouteCheckpoint::class, 'route_id')->orderBy('sequence');
    }

    /** Checkpoints ordered by sequence via pivot. */
    public function checkpoints(): BelongsToMany
    {
        return $this->belongsToMany(Checkpoint::class, 'route_checkpoints', 'route_id', 'checkpoint_id')
            ->withPivot(['sequence', 'is_required'])
            ->orderBy('route_checkpoints.sequence');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(PatrolSchedule::class, 'route_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PatrolSession::class, 'route_id');
    }

    public function isSequential(): bool
    {
        return $this->route_type === 'SEQUENTIAL';
    }

    public function isFlexible(): bool
    {
        return $this->route_type === 'FLEXIBLE';
    }
}
