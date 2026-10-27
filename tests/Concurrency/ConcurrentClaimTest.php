<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Enums\CouponClaimStatus;
use App\Models\Campaign;
use App\Models\CouponClaim;
use App\Models\CouponLog;
use App\Models\User;
use App\Services\CouponService;

final class ConcurrentClaimTest extends ConcurrencyTestCase
{
    public function test_last_coupon_race_ten_users_one_slot_exactly_one_winner(): void
    {
        $campaign = Campaign::factory()->withQuantity(total: 5, claimed: 4)->create();
        CouponClaim::factory()->count(4)->for($campaign)->create();
        $users = User::factory()->count(10)->create();

        $results = $this->runConcurrently(
            $users->map(fn (User $user) => fn () => app(CouponService::class)->claim($user, $campaign->id)->id)->all()
        );

        $this->assertSame(['CouponQuotaExhaustedException' => 9, 'ok' => 1], $this->tally($results));
        $this->assertSame(5, $campaign->fresh()->claimed_quantity);
        $this->assertSame(5, $campaign->claims()->whereIn('status', CouponClaimStatus::holdingValues())->count());
    }

    public function test_forty_users_compete_for_ten_slots(): void
    {
        $campaign = Campaign::factory()->withQuantity(total: 10)->create();
        $users = User::factory()->count(40)->create();

        $results = $this->runConcurrently(
            $users->map(fn (User $user) => fn () => app(CouponService::class)->claim($user, $campaign->id)->id)->all()
        );

        $this->assertSame(['CouponQuotaExhaustedException' => 30, 'ok' => 10], $this->tally($results));
        $this->assertSame(10, $campaign->fresh()->claimed_quantity);
        $this->assertSame(10, $campaign->claims()->distinct()->count('user_id'));
        $this->assertSame(10, CouponLog::query()->where('new_status', 'claimed')->count());
    }

    public function test_same_user_double_clicking_claim_gets_exactly_one_claim(): void
    {
        $campaign = Campaign::factory()->withQuantity(total: 100)->create();
        $user = User::factory()->create();

        $results = $this->runConcurrently(
            array_fill(0, 8, fn () => app(CouponService::class)->claim($user, $campaign->id)->id)
        );

        $this->assertSame(['DuplicateClaimException' => 7, 'ok' => 1], $this->tally($results));
        $this->assertSame(1, $campaign->fresh()->claimed_quantity);
        $this->assertSame(1, $campaign->claims()->count());
    }

    public function test_concurrent_cancel_and_claim_keep_the_counter_consistent(): void
    {
        // 5 holders cancel while 5 newcomers claim a full campaign at the same time.
        $campaign = Campaign::factory()->withQuantity(total: 5, claimed: 5)->create();
        $holders = User::factory()->count(5)->create();
        $claims = $holders->map(fn (User $u) => CouponClaim::factory()->for($campaign)->for($u)->create());
        $newcomers = User::factory()->count(5)->create();

        $tasks = [];
        foreach ($holders as $i => $holder) {
            $claimId = $claims[$i]->id;
            $tasks[] = fn () => app(CouponService::class)->cancel($holder, $claimId)->id;
        }
        foreach ($newcomers as $newcomer) {
            $tasks[] = fn () => app(CouponService::class)->claim($newcomer, $campaign->id)->id;
        }

        $this->runConcurrently($tasks);

        // Whatever the interleaving, the counter must equal the real number of holders.
        $holding = $campaign->claims()->whereIn('status', CouponClaimStatus::holdingValues())->count();
        $this->assertSame($holding, $campaign->fresh()->claimed_quantity);
        $this->assertLessThanOrEqual(5, $holding);
        $this->assertSame(5, $campaign->claims()->where('status', 'cancelled')->count());
    }
}
