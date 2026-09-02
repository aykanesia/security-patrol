<?php

namespace App\Enums;

enum RoleName: string
{
    case SUPER_ADMIN = 'super_admin';
    case SUPERVISOR = 'supervisor';
    case SECURITY = 'security';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super Admin',
            self::SUPERVISOR => 'Supervisor',
            self::SECURITY => 'Security',
        };
    }
}
