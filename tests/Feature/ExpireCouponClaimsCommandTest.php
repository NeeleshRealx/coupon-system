<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CouponClaimStatus;
use App\Models\Campaign;
use App\Models\CouponClaim;
use App\Models\CouponLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExpireCouponClaimsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-16 12:00:00', 'UTC'));
    }

    public function test_expires_only_overdue_claimed_coupons_and_releases_their_slots(): void
    {
        $campaign = Campaign::factory()->withQuantity(3, 3)->create();
        $overdue = CouponClaim::factory()->for($campaign)->overdue()->create();
        $active = CouponClaim::factory()->for($campaign)->create();
        $redeemedPastExpiry = CouponClaim::factory()->for($campaign)->overdue()->redeemed()->create();

        $this->artisan('coupons:expire')->assertSuccessful();

        $this->assertSame(CouponClaimStatus::Expired, $overdue->fresh()->status);
        $this->assertSame(CouponClaimStatus::Claimed, $active->fresh()->status);
        $this->assertSame(CouponClaimStatus::Redeemed, $redeemedPastExpiry->fresh()->status);
        $this->assertSame(2, $campaign->fresh()->claimed_quantity);
        $this->assertDatabaseHas('coupon_logs', [
            'coupon_claim_id' => $overdue->id,
            'old_status' => 'claimed',
            'new_status' => 'expired',
            'actor_id' => null,
            'remarks' => 'Expired by scheduler.',
        ]);
    }

    public function test_boundary_claim_expiring_exactly_now_is_expired_one_second_later_is_not(): void
    {
        $campaign = Campaign::factory()->withQuantity(2, 2)->create();
        $exact = CouponClaim::factory()->for($campaign)->create(['expires_at' => now()]);
        $notYet = CouponClaim::factory()->for($campaign)->create(['expires_at' => now()->addSecond()]);

        $this->artisan('coupons:expire')->assertSuccessful();

        $this->assertSame(CouponClaimStatus::Expired, $exact->fresh()->status);
        $this->assertSame(CouponClaimStatus::Claimed, $notYet->fresh()->status);
    }

    public function test_running_twice_is_idempotent(): void
    {
        $campaign = Campaign::factory()->withQuantity(2, 2)->create();
        CouponClaim::factory()->count(2)->for($campaign)->overdue()->create();

        $this->artisan('coupons:expire')->assertSuccessful();
        $this->artisan('coupons:expire')->assertSuccessful();

        $this->assertSame(0, $campaign->fresh()->claimed_quantity);
        $this->assertSame(2, CouponLog::query()->where('new_status', 'expired')->count());
    }

    public function test_catches_up_after_a_long_outage_in_small_chunks(): void
    {
        // Cron was down for 3 hours: many claims are overdue at once.
        $campaign = Campaign::factory()->withQuantity(25, 25)->create();
        CouponClaim::factory()->count(25)->for($campaign)->create([
            'claimed_at' => now()->subHours(27),
            'expires_at' => now()->subHours(3),
        ]);

        $this->artisan('coupons:expire', ['--chunk' => 4])->assertSuccessful();

        $this->assertSame(0, $campaign->fresh()->claimed_quantity);
        $this->assertSame(25, CouponClaim::query()->where('status', 'expired')->count());
    }
}
