<?php

declare(strict_types=1);

namespace App\Exceptions\Coupon;

final class CampaignNotActiveException extends CouponException
{
    public function __construct(string $message = 'This campaign is not currently active.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'campaign_not_active';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
