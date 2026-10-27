<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CouponClaimStatus;
use App\Models\Campaign;
use App\Models\CouponClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * NOTE: this factory writes rows directly and does NOT touch
 * campaigns.claimed_quantity. Tests that need a consistent counter set it
 * explicitly (CampaignFactory::withQuantity) or go through CouponService.
 *
 * @extends Factory<CouponClaim>
 */
class CouponClaimFactory extends Factory
{
    public function definition(): array
    {
        $claimedAt = now()->startOfSecond();

        return [
            'campaign_id' => Campaign::factory(),
            'user_id' => User::factory(),
            'claimed_at' => $claimedAt,
            'expires_at' => $claimedAt->copy()->addHours(CouponClaim::VALIDITY_HOURS),
            'status' => CouponClaimStatus::Claimed,
        ];
    }

    /** Status still "claimed" but the 24h window has already passed (scheduler not run yet). */
    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'claimed_at' => now()->startOfSecond()->subHours(25),
            'expires_at' => now()->startOfSecond()->subHour(),
        ]);
    }

    public function redeemed(): static
    {
        return $this->state(fn (): array => ['status' => CouponClaimStatus::Redeemed]);
    }

    public function expired(): static
    {
        return $this->overdue()->state(fn (): array => ['status' => CouponClaimStatus::Expired]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => CouponClaimStatus::Cancelled]);
    }
}
