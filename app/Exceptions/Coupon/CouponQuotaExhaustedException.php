<?php

declare(strict_types=1);

namespace App\Exceptions\Coupon;

final class CouponQuotaExhaustedException extends CouponException
{
    public function __construct(string $message = 'All coupons for this campaign have been claimed.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'quota_exhausted';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
