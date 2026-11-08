<?php

namespace App\Enum;

enum ComplaintCategory: string
{
    case BILLING = 'BILLING';
    case VEHICLE = 'VEHICLE';
    case SERVICE = 'SERVICE';
    case DAMAGE_DISPUTE = 'DAMAGE_DISPUTE';
}
