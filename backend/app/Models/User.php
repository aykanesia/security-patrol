<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'role_id', 'employee_code', 'name', 'username', 'password',
        'phone', 'photo', 'status', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function patrolSessions(): HasMany
    {
        return $this->hasMany(PatrolSession::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(PatrolScheduleAssignment::class);
    }

    // ------------------------------------------------------------ helpers

    public function hasRole(RoleName|string $role): bool
    {
        $name = $role instanceof RoleName ? $role->value : $role;

        return $this->role?->name === $name;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE->value;
    }
}
