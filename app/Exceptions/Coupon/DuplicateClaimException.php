<?php

declare(strict_types=1);

namespace App\Exceptions\Coupon;

final class DuplicateClaimException extends CouponException
{
    public function __construct(private readonly ?int $existingClaimId = null)
    {
        parent::__construct('You already hold a coupon for this campaign.');
    }

    public function errorCode(): string
    {
        return 'duplicate_claim';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return $this->existingClaimId === null ? [] : ['claim_id' => $this->existingClaimId];
    }
}
