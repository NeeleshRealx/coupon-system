<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Enums\CouponClaimStatus;
use App\Models\Campaign;
use App\Models\CouponClaim;
use App\Models\CouponLog;
use App\Models\CouponRedemption;
use App\Models\User;
use App\Services\CouponService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Expiry collision": the cron job expires a claim at the same moment the user
 * redeems it. Exactly one of them may win, and the counter/logs must agree.
 */
final class SchedulerExpiryRaceTest extends ConcurrencyTestCase
{
    private const EXPIRES_AT = '2026-06-17 12:00:00';

    private CarbonImmutable $expiresAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->expiresAt = CarbonImmutable::parse(self::EXPIRES_AT, 'UTC');
        $this->travelTo($this->expiresAt->subHour());
    }

    private function claimExpiringAtBoundary(Campaign $campaign, ?User $user = null): CouponClaim
    {
        return CouponClaim::factory()
            ->for($campaign)
            ->for($user ?? User::factory())
            ->create(['claimed_at' => $this->expiresAt->subDay(), 'expires_at' => $this->expiresAt]);
    }

    public function test_redeem_and_scheduler_racing_never_both_win(): void
    {
        $campaign = Campaign::factory()->withQuantity(10, 10)->create();
        $claims = collect(range(1, 10))->map(fn () => $this->claimExpiringAtBoundary($campaign));

        $tasks = [];
        foreach ($claims as $claim) {
            // User's request is processed while the clock still says 1s before expiry...
            $tasks[] = function () use ($claim): string {
                $this->travelTo($this->expiresAt->subSecond());
                app(CouponService::class)->redeem($claim->user, $claim->id, 'TX-'.$claim->id);

                return 'redeemed';
            };
            // ...while the cron's clock already says the claim has expired.
            $tasks[] = function () use ($claim): string {
                $this->travelTo($this->expiresAt);

                return app(CouponService::class)->expireOverdueClaim($claim->id, $claim->campaign_id) ? 'expired' : 'skipped';
            };
        }

        $this->runConcurrently($tasks);

        $expiredCount = 0;
        foreach ($claims as $claim) {
            $fresh = $claim->fresh();
            $redemptions = CouponRedemption::query()->where('coupon_claim_id', $claim->id)->count();
            $logs = CouponLog::query()
                ->where('coupon_claim_id', $claim->id)
                ->orderBy('id')
                ->get()
                ->map(fn (CouponLog $log): string => $log->new_status->value)
                ->all();

            if ($fresh->status === CouponClaimStatus::Redeemed) {
                $this->assertSame(1, $redemptions);
                $this->assertSame(['redeemed'], $logs);
            } else {
                $this->assertSame(CouponClaimStatus::Expired, $fresh->status);
                $this->assertSame(0, $redemptions);
                $this->assertSame(['expired'], $logs);
                $expiredCount++;
            }
        }

        // Only expiries release slots; redemptions keep theirs.
        $this->assertSame(10 - $expiredCount, $campaign->fresh()->claimed_quantity);
    }

    public function test_scheduler_waits_for_an_in_flight_redemption_and_then_skips_it(): void
    {
        $campaign = Campaign::factory()->withQuantity(1, 1)->create();
        $claim = $this->claimExpiringAtBoundary($campaign);

        $results = $this->runConcurrently(
            [
                // Process A: simulates a redemption that holds the claim lock for 1.5s.
                function () use ($claim): string {
                    DB::transaction(function () use ($claim): void {
                        DB::table('coupon_claims')->where('id', $claim->id)->lockForUpdate()->first();
                        usleep(1_500_000);
                        DB::table('coupon_claims')->where('id', $claim->id)->update(['status' => 'redeemed']);
                    });

                    return 'redeemed';
                },
                // Process B: the cron, 0.4s later, with a clock past expiry.
                function () use ($claim): array {
                    $this->travelTo($this->expiresAt->addSecond());
                    $started = microtime(true);
                    $expired = app(CouponService::class)->expireOverdueClaim($claim->id, $claim->campaign_id);

                    return ['expired' => $expired, 'waited' => microtime(true) - $started];
                },
            ],
            startOffsets: [0.0, 0.4],
        );

        $this->assertTrue($results[0]['ok'], (string) $results[0]['message']);
        $this->assertTrue($results[1]['ok'], (string) $results[1]['message']);
        $this->assertFalse($results[1]['value']['expired'], 'Scheduler must not expire a claim that was redeemed while it waited.');
        $this->assertGreaterThan(0.8, $results[1]['value']['waited'], 'Scheduler should have blocked on the row lock.');
        $this->assertSame(CouponClaimStatus::Redeemed, $claim->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->claimed_quantity);
    }

    public function test_redemption_waits_for_an_in_flight_expiry_and_is_then_rejected(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->withQuantity(1, 1)->create();
        $claim = $this->claimExpiringAtBoundary($campaign, $user);

        $results = $this->runConcurrently(
            [
                // Process A: simulates the cron mid-expiry (campaign → claim lock order), holding locks 1.5s.
                function () use ($claim): string {
                    DB::transaction(function () use ($claim): void {
                        DB::table('campaigns')->where('id', $claim->campaign_id)->lockForUpdate()->first();
                        DB::table('coupon_claims')->where('id', $claim->id)->lockForUpdate()->first();
                        DB::table('coupon_claims')->where('id', $claim->id)->update(['status' => 'expired']);
                        DB::table('campaigns')->where('id', $claim->campaign_id)->decrement('claimed_quantity');
                        usleep(1_500_000);
                    });

                    return 'expired';
                },
                // Process B: the user, whose clock still says "1 second left".
                function () use ($claim, $user): string {
                    $this->travelTo($this->expiresAt->subSecond());
                    app(CouponService::class)->redeem($user, $claim->id, 'TX-LATE');

                    return 'redeemed';
                },
            ],
            startOffsets: [0.0, 0.4],
        );

        $this->assertTrue($results[0]['ok'], (string) $results[0]['message']);
        // The clock alone would have allowed it; the re-check under the lock must not.
        $this->assertSame('InvalidClaimStateException', $results[1]['error']);
        $this->assertSame(CouponClaimStatus::Expired, $claim->fresh()->status);
        $this->assertSame(0, CouponRedemption::query()->count());
        $this->assertSame(0, $campaign->fresh()->claimed_quantity);
    }
}
