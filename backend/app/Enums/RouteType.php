<?php

namespace App\Enums;

enum RouteType: string
{
    case SEQUENTIAL = 'SEQUENTIAL';
    case FLEXIBLE = 'FLEXIBLE';

    public function label(): string
    {
        return $this === self::SEQUENTIAL ? 'Sequential' : 'Flexible';
    }
}
