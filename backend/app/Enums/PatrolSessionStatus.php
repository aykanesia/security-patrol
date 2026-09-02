<?php

namespace App\Enums;

enum PatrolSessionStatus: string
{
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case INCOMPLETE = 'INCOMPLETE';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::RUNNING => 'Running',
            self::COMPLETED => 'Completed',
            self::INCOMPLETE => 'Incomplete',
            self::CANCELLED => 'Cancelled',
        };
    }
}
