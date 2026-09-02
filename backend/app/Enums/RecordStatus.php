<?php

namespace App\Enums;

enum RecordStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';

    public function label(): string
    {
        return $this === self::ACTIVE ? 'Active' : 'Inactive';
    }
}
