<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CouponClaimStatus;
use App\Models\Campaign;
use App\Models\CouponClaim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ClaimCouponTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-06-16 11:41:22';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
    }

    public function test_user_can_claim_a_coupon_from_an_active_campaign(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->withQuantity(100)->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/coupons/claim', ['campaign_id' => $campaign->id]);

        $claim = CouponClaim::query()->sole();
        $response->assertCreated()->assertExactJson([
            'claim_id' => $claim->id,
            'expires_at' => '2026-06-17T11:41:22Z', // exactly 24h later, UTC, spec format
        ]);
        $this->assertSame(CouponClaimStatus::Claimed, $claim->status);
        $this->assertSame($user->id, $claim->user_id);
        $this->assertSame(1, $campaign->fresh()->claimed_quantity);
        $this->assertDatabaseHas('coupon_logs', [
            'coupon_claim_id' => $claim->id,
            'old_status' => null,
            'new_status' => 'claimed',
            'actor_id' => $user->id,
        ]);
    }

    public function test_unauthenticated_requests_get_a_json_401_even_without_accept_header(): void
    {
        $campaign = Campaign::factory()->create();

        $this->postJson('/api/coupons/claim', ['campaign_id' => $campaign->id])->assertUnauthorized();
        $this->post('/api/coupons/claim', ['campaign_id' => $campaign->id])
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);

        $this->assertDatabaseCount('coupon_claims', 0);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing campaign_id' => [[]],
            'non-numeric campaign_id' => [['campaign_id' => 'abc']],
            'zero campaign_id' => [['campaign_id' => 0]],
            'array campaign_id' => [['campaign_id' => [1]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_rejected_with_422(array $payload): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/coupons/claim', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('campaign_id');
    }

    public function test_unknown_campaign_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/coupons/claim', ['campaign_id' => 999999])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'campaign_not_found');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function inactiveCampaignStates(): array
    {
        return [
            'paused' => ['paused'],
            'not started yet' => ['upcoming'],
            'already ended' => ['ended'],
        ];
    }

    #[DataProvider('inactiveCampaignStates')]
    public function test_claim_is_rejected_when_campaign_is_not_active(string $state): void
    {
        $campaign = Campaign::factory()->{$state}()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/coupons/claim', ['campaign_id' => $campaign->id])
            ->assertConflict()
            ->assertJsonPath('error.code', 'campaign_not_active');

        $this->assertDatabaseCount('coupon_claims', 0);
        $this->assertSame(0, $campaign->fresh()->claimed_quantity);
    }

    public function test_campaign_window_is_inclusive_of_start_and_exclusive_of_end(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $startsNow = Campaign::factory()->create(['starts_at' => now(), 'expires_at' => now()->addDay()]);
        $endsNow = Campaign::factory()->create(['starts_at' => now()->subDay(), 'expires_at' => now()]);

        $this->postJson('/api/coupons/claim', ['campaign_id' => $startsNow->id])->assertCreated();
        $this->postJson('/api/coupons/claim', ['campaign_id' => $endsNow->id])
            ->assertConflict()
            ->assertJsonPath('error.code', 'campaign_not_active');
    }

    public function test_user_cannot_claim_twice_for_the_same_campaign(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->withQuantity(100)->create();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/coupons/claim', ['campaign_id' => $campaign->id])->assertCreated();

        $this->postJson('/api/coupons/claim', ['campaign_id' => $campaign->id])
            ->assertConflict()
            ->assertJsonPath('error.code', 'duplicate_claim')
            ->assertJsonPath('error.details.claim_id', $first->json('claim_id'));

        $this->assertDatabaseCount('coupon_claims', 1);
        $this->assertSame(1, $campaign->fresh()->claimed_quantity);
    }

    public function test_a_redeemed_claim_also_blocks_a_new_claim(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->withQuantity(10, 1)->create();
        CouponClaim::factory()->for($campaign)->for($user)->redeemed()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/coupons/claim', ['campaign_id' => $campaign->id])
            ->assertConflict()
            ->assertJsonPath('error.code', 'duplicate_claim');
    }

    public function test_user_may_claim_again_after_a_previous_claim_was_cancelled(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->withQuantity(10, 0)->create();
        CouponClaim::factory()->for($campaign)->for($user)->cancelled()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/coupons/claim', ['campaign_id' => $campaign->id])->assertCreated();

        $this->assertSame(1, $campaign->fresh()->claimed_quantity);
    }

    public function test_claim_is_rejected_when_quota_is_exhausted(): void
    {
        $campaign = Campaign::factory()->withQuantity(2, 2)->create();
        CouponClaim::factory()->count(2)->for($campaign)->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/coupons/claim', ['campaign_id' => $campaign->id])
            ->assertConflict()
            ->assertJsonPath('error.code', 'quota_exhausted');

        $this->assertSame(2, $campaign->fresh()->claimed_quantity);
        $this->assertDatabaseMissing('coupon_claims', ['user_id' => $user->id]);
    }

    public function test_full_campaign_self_heals_overdue_claims_when_scheduler_is_behind(): void
    {
        // Slot is held by a claim whose 24h passed, but the cron never ran.
        $campaign = Campaign::factory()->withQuantity(1, 1)->create();
        $stale = CouponClaim::factory()->for($campaign)->overdue()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/coupons/claim', ['campaign_id' => $campaign->id])->assertCreated();

        $this->assertSame(CouponClaimStatus::Expired, $stale->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->claimed_quantity);
        $this->assertDatabaseHas('coupon_logs', [
            'coupon_claim_id' => $stale->id,
            'old_status' => 'claimed',
            'new_status' => 'expired',
            'actor_id' => null,
        ]);
    }

    public function test_user_can_reclaim_when_their_own_previous_claim_is_overdue_but_not_yet_marked_expired(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->withQuantity(5, 1)->create();
        $stale = CouponClaim::factory()->for($campaign)->for($user)->overdue()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/coupons/claim', ['campaign_id' => $campaign->id])->assertCreated();

        $this->assertSame(CouponClaimStatus::Expired, $stale->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->claimed_quantity); // -1 released, +1 new
        $this->assertSame(1, CouponClaim::query()->where('user_id', $user->id)->where('status', 'claimed')->count());
    }

    public function test_database_rejects_a_second_holding_claim_even_if_application_checks_are_bypassed(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create();

        // Any number of non-holding (expired/cancelled) rows is allowed...
        CouponClaim::factory()->for($campaign)->for($user)->expired()->create();
        CouponClaim::factory()->for($campaign)->for($user)->cancelled()->create();
        CouponClaim::factory()->for($campaign)->for($user)->create();

        // ...but a second claimed/redeemed row violates the unique index.
        $this->expectException(UniqueConstraintViolationException::class);
        CouponClaim::factory()->for($campaign)->for($user)->redeemed()->create();
    }

    public function test_database_check_constraint_rejects_oversubscription(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraint is created on MySQL/PostgreSQL only.');
        }

        $campaign = Campaign::factory()->withQuantity(3, 3)->create();

        $this->expectException(QueryException::class);
        DB::table('campaigns')->where('id', $campaign->id)->update(['claimed_quantity' => 4]);
    }
}
