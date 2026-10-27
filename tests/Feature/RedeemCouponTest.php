<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CouponClaimStatus;
use App\Models\Campaign;
use App\Models\CouponClaim;
use App\Models\CouponLog;
use App\Models\User;
use App\Services\CouponService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class RedeemCouponTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-16 11:41:22', 'UTC'));
        $this->user = User::factory()->create();
    }

    private function claimFor(User $user, ?callable $state = null): CouponClaim
    {
        $campaign = Campaign::factory()->withQuantity(10, 1)->create();
        $factory = CouponClaim::factory()->for($campaign)->for($user);

        return ($state ? $state($factory) : $factory)->create();
    }

    public function test_user_can_redeem_their_claim(): void
    {
        $claim = $this->claimFor($this->user);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/coupons/redeem', [
            'claim_id' => $claim->id,
            'transaction_id' => 'TX123456',
            'order_id' => 'ORD-1001',
        ])
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJson([
                'claim_id' => $claim->id,
                'transaction_id' => 'TX123456',
                'order_id' => 'ORD-1001',
                'status' => 'completed',
            ]);

        $this->assertSame(CouponClaimStatus::Redeemed, $claim->fresh()->status);
        $this->assertDatabaseCount('coupon_redemptions', 1);
        $this->assertDatabaseHas('coupon_logs', [
            'coupon_claim_id' => $claim->id,
            'old_status' => 'claimed',
            'new_status' => 'redeemed',
            'actor_id' => $this->user->id,
        ]);
        // Redeeming keeps the slot occupied (it was "used", not released).
        $this->assertSame(1, $claim->campaign->fresh()->claimed_quantity);
    }

    public function test_order_id_is_optional(): void
    {
        $claim = $this->claimFor($this->user);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/coupons/redeem', ['claim_id' => $claim->id, 'transaction_id' => 'TX1'])
            ->assertCreated()
            ->assertJsonPath('order_id', null);
    }

    public function test_impatient_user_repeating_the_same_request_redeems_exactly_once(): void
    {
        $claim = $this->claimFor($this->user);
        Sanctum::actingAs($this->user);
        $payload = ['claim_id' => $claim->id, 'transaction_id' => 'TX123456'];

        $first = $this->postJson('/api/coupons/redeem', $payload)->assertCreated();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/coupons/redeem', $payload)
                ->assertOk()
                ->assertHeader('Idempotent-Replayed', 'true')
                ->assertJsonPath('redemption_id', $first->json('redemption_id'));
        }

        $this->assertDatabaseCount('coupon_redemptions', 1);
        $this->assertSame(1, CouponLog::query()->where('new_status', 'redeemed')->count());
    }

    public function test_a_new_transaction_id_on_an_already_redeemed_claim_is_rejected(): void
    {
        $claim = $this->claimFor($this->user);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/coupons/redeem', ['claim_id' => $claim->id, 'transaction_id' => 'TX-A'])->assertCreated();

        $this->postJson('/api/coupons/redeem', ['claim_id' => $claim->id, 'transaction_id' => 'TX-B'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'invalid_claim_state')
            ->assertJsonPath('error.details.status', 'redeemed');

        $this->assertDatabaseCount('coupon_redemptions', 1);
    }

    public function test_transaction_id_reused_for_a_different_claim_is_a_conflict(): void
    {
        $claimA = $this->claimFor($this->user);
        $claimB = $this->claimFor($this->user);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/coupons/redeem', ['claim_id' => $claimA->id, 'transaction_id' => 'TX-SHARED'])->assertCreated();

        $this->postJson('/api/coupons/redeem', ['claim_id' => $claimB->id, 'transaction_id' => 'TX-SHARED'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'transaction_id_conflict');

        $this->assertSame(CouponClaimStatus::Claimed, $claimB->fresh()->status);
    }

    public function test_user_cannot_redeem_someone_elses_claim(): void
    {
        $claim = $this->claimFor(User::factory()->create());
        Sanctum::actingAs($this->user);

        // 404 (not 403) so claim ids of other users cannot be probed.
        $this->postJson('/api/coupons/redeem', ['claim_id' => $claim->id, 'transaction_id' => 'TX1'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'claim_not_found');

        $this->assertSame(CouponClaimStatus::Claimed, $claim->fresh()->status);
        $this->assertDatabaseCount('coupon_redemptions', 0);
    }

    public function test_unknown_claim_returns_404(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/coupons/redeem', ['claim_id' => 999999, 'transaction_id' => 'TX1'])->assertNotFound();
    }

    public function test_claim_past_its_expiry_cannot_be_redeemed_even_if_the_scheduler_has_not_run(): void
    {
        $claim = $this->claimFor($this->user, fn ($f) => $f->overdue());
        Sanctum::actingAs($this->user);

        $this->postJson('/api/coupons/redeem', ['claim_id' => $claim->id, 'transaction_id' => 'TX1'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'claim_expired');

        $this->assertDatabaseCount('coupon_redemptions', 0);
    }

    public function test_expiry_boundary_one_second_before_is_valid_and_exact_expiry_is_not(): void
    {
        $early = $this->claimFor($this->user);   // expires 2026-06-17 11:41:22
        $late = $this->claimFor($this->user);
        Sanctum::actingAs($this->user);

        $this->travelTo(CarbonImmutable::parse('2026-06-17 11:41:21', 'UTC'));
        $this->postJson('/api/coupons/redeem', ['claim_id' => $early->id, 'transaction_id' => 'TX-EARLY'])->assertCreated();

        $this->travelTo(CarbonImmutable::parse('2026-06-17 11:41:22', 'UTC'));
        $this->postJson('/api/coupons/redeem', ['claim_id' => $late->id, 'transaction_id' => 'TX-LATE'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'claim_expired');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonRedeemableStates(): array
    {
        return ['expired' => ['expired'], 'cancelled' => ['cancelled']];
    }

    #[DataProvider('nonRedeemableStates')]
    public function test_claim_in_a_terminal_state_cannot_be_redeemed(string $state): void
    {
        $claim = $this->claimFor($this->user, fn ($f) => $f->{$state}());
        Sanctum::actingAs($this->user);

        $this->postJson('/api/coupons/redeem', ['claim_id' => $claim->id, 'transaction_id' => 'TX1'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'invalid_claim_state')
            ->assertJsonPath('error.details.status', $state);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing transaction_id' => [['claim_id' => 1], 'transaction_id'],
            'transaction_id too long' => [['claim_id' => 1, 'transaction_id' => str_repeat('A', 65)], 'transaction_id'],
            'transaction_id with unsafe chars' => [['claim_id' => 1, 'transaction_id' => "TX'; DROP TABLE x;--"], 'transaction_id'],
            'missing claim_id' => [['transaction_id' => 'TX1'], 'claim_id'],
            'order_id too long' => [['claim_id' => 1, 'transaction_id' => 'TX1', 'order_id' => str_repeat('9', 65)], 'order_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_rejected(array $payload, string $field): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/coupons/redeem', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_failure_mid_transaction_rolls_back_everything(): void
    {
        $claim = $this->claimFor($this->user);

        // Make the audit-log insert (the last write of redeem) blow up.
        CouponLog::creating(static function (): never {
            throw new RuntimeException('Simulated failure after redemption insert');
        });

        try {
            app(CouponService::class)->redeem($this->user, $claim->id, 'TX-ROLLBACK');
            $this->fail('Expected the simulated failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure after redemption insert', $e->getMessage());
        }

        $this->assertDatabaseCount('coupon_redemptions', 0);
        $this->assertSame(CouponClaimStatus::Claimed, $claim->fresh()->status);
    }
}
