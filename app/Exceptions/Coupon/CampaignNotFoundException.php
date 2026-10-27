<?php

declare(strict_types=1);

namespace App\Exceptions\Coupon;

final class CampaignNotFoundException extends CouponException
{
    public function __construct(string $message = 'Campaign not found.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'campaign_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
