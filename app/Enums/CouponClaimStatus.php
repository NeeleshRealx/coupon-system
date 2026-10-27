<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a coupon claim (explicit state machine).
 *
 *   claimed ──► redeemed ──► cancelled   (redeemed → cancelled only via admin revoke)
 *      │
 *      ├──► expired     (terminal)
 *      └──► cancelled   (terminal)
 */
enum CouponClaimStatus: string
{
    case Claimed = 'claimed';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Claimed => [self::Redeemed, self::Expired, self::Cancelled],
            self::Redeemed => [self::Cancelled],
            self::Expired, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Whether a claim in this status occupies one of the campaign's slots
     * (i.e. is counted in campaigns.claimed_quantity).
     */
    public function holdsSlot(): bool
    {
        return $this === self::Claimed || $this === self::Redeemed;
    }

    /**
     * @return list<string>
     */
    public static function holdingValues(): array
    {
        return [self::Claimed->value, self::Redeemed->value];
    }
}
