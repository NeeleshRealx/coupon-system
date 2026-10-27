<?php

declare(strict_types=1);

namespace App\Exceptions\Coupon;

use App\Enums\CouponClaimStatus;

final class InvalidClaimStateException extends CouponException
{
    public function __construct(
        private readonly CouponClaimStatus $currentStatus,
        string $action,
    ) {
        parent::__construct(sprintf('Cannot %s a coupon claim that is %s.', $action, $currentStatus->value));
    }

    public function errorCode(): string
    {
        return 'invalid_claim_state';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['status' => $this->currentStatus->value];
    }
}
