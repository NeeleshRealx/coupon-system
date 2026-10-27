<?php

declare(strict_types=1);

namespace App\Exceptions\Coupon;

final class TransactionIdConflictException extends CouponException
{
    public function __construct(string $message = 'This transaction_id has already been used for a different coupon claim.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'transaction_id_conflict';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
