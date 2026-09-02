<?php

namespace App\Enums;

enum CheckinSyncStatus: string
{
    case SYNCED = 'SYNCED';
    case PENDING = 'PENDING';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::SYNCED => 'Synced',
            self::PENDING => 'Pending',
            self::FAILED => 'Failed',
        };
    }
}
