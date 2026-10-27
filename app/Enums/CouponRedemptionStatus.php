<?php

declare(strict_types=1);

namespace App\Enums;

enum CouponRedemptionStatus: string
{
    case Completed = 'completed';
    case Voided = 'voided'; // the claim was revoked by an admin after redemption
}
