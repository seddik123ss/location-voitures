<?php

namespace App\Enum;

enum ComplaintCategory: string
{
    case BILLING = 'BILLING';
    case VEHICLE = 'VEHICLE';
    case SERVICE = 'SERVICE';
    case DAMAGE_DISPUTE = 'DAMAGE_DISPUTE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
