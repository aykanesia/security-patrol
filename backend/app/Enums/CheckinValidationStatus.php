<?php

namespace App\Enums;

enum CheckinValidationStatus: string
{
    case VALID = 'VALID';
    case INVALID_LOCATION = 'INVALID_LOCATION';
    case INVALID_CHECKPOINT = 'INVALID_CHECKPOINT';
    case DUPLICATE = 'DUPLICATE';
    case INVALID_SESSION = 'INVALID_SESSION';
    case INVALID_SEQUENCE = 'INVALID_SEQUENCE';

    public function label(): string
    {
        return match ($this) {
            self::VALID => 'Valid',
            self::INVALID_LOCATION => 'Invalid location',
            self::INVALID_CHECKPOINT => 'Invalid checkpoint',
            self::DUPLICATE => 'Duplicate',
            self::INVALID_SESSION => 'Invalid session',
            self::INVALID_SEQUENCE => 'Invalid sequence',
        };
    }
}
