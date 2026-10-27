<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CouponRedemption;

/**
 * Outcome of a redeem call. $replayed = true means the same transaction_id was
 * already processed for this claim and the original result is being returned
 * (idempotent replay) — no state was changed by this call.
 */
final readonly class RedemptionResult
{
    public function __construct(
        public CouponRedemption $redemption,
        public bool $replayed,
    ) {
    }
}
