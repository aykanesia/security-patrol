<?php

namespace App\Enums;

enum DeviceStatus: string
{
    case ACTIVE = 'ACTIVE';
    case BLOCKED = 'BLOCKED';

    public function label(): string
    {
        return $this === self::ACTIVE ? 'Active' : 'Blocked';
    }
}
