<?php

declare(strict_types=1);

namespace App\Exceptions\Coupon;

final class ClaimExpiredException extends CouponException
{
    public function __construct(string $message = 'This coupon claim has expired.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'claim_expired';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
