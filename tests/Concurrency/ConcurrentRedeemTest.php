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

final class ConcurrentRedeemTest extends ConcurrencyTestCase
{
    private function claimFor(User $user): CouponClaim
    {
        $campaign = Campaign::factory()->withQuantity(10, 1)->create();

        return CouponClaim::factory()->for($campaign)->for($user)->create();
    }

    public function test_impatient_user_five_identical_requests_redeem_exactly_once(): void
    {
        $user = User::factory()->create();
        $claim = $this->claimFor($user);

        $redeem = function () use ($user, $claim): array {
            $result = app(CouponService::class)->redeem($user, $claim->id, 'TX123456');

            return ['redemption_id' => $result->redemption->id, 'replayed' => $result->replayed];
        };

        $results = $this->runConcurrently(array_fill(0, 5, $redeem));

        // Every request succeeds (no 500s); exactly one did the work, four replayed it.
        $this->assertSame(['ok' => 5], $this->tally($results));
        $values = array_column($results, 'value');
        $this->assertCount(1, array_filter($values, fn (array $v) => $v['replayed'] === false));
        $this->assertCount(1, array_unique(array_column($values, 'redemption_id')));

        $this->assertSame(1, CouponRedemption::query()->count());
        $this->assertSame(CouponClaimStatus::Redeemed, $claim->fresh()->status);
        $this->assertSame(1, CouponLog::query()->where('new_status', 'redeemed')->count());
    }

    public function test_concurrent_redeems_with_different_transaction_ids_redeem_only_once(): void
    {
        $user = User::factory()->create();
        $claim = $this->claimFor($user);

        $tasks = array_map(
            fn (int $i) => fn () => app(CouponService::class)->redeem($user, $claim->id, 'TX-'.$i)->redemption->id,
            range(1, 5),
        );

        $results = $this->runConcurrently($tasks);

        $this->assertSame(['InvalidClaimStateException' => 4, 'ok' => 1], $this->tally($results));
        $this->assertSame(1, CouponRedemption::query()->count());
    }

    public function test_same_transaction_id_on_two_claims_concurrently_is_used_once(): void
    {
        $user = User::factory()->create();
        $claimA = $this->claimFor($user);
        $claimB = $this->claimFor($user);

        $results = $this->runConcurrently([
            fn () => app(CouponService::class)->redeem($user, $claimA->id, 'TX-SHARED')->redemption->id,
            fn () => app(CouponService::class)->redeem($user, $claimB->id, 'TX-SHARED')->redemption->id,
        ]);

        $this->assertSame(['TransactionIdConflictException' => 1, 'ok' => 1], $this->tally($results));
        $this->assertSame(1, CouponRedemption::query()->where('transaction_id', 'TX-SHARED')->count());
        $this->assertSame(1, CouponClaim::query()->where('status', 'redeemed')->count());
    }
}
