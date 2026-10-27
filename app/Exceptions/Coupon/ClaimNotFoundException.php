<?php

declare(strict_types=1);

namespace App\Exceptions\Coupon;

final class ClaimNotFoundException extends CouponException
{
    public function __construct(string $message = 'Coupon claim not found.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'claim_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
