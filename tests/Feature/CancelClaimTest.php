<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CouponClaimStatus;
use App\Models\Campaign;
use App\Models\CouponClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CancelClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_cancel_and_the_slot_is_released(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->withQuantity(1, 1)->create();
        $claim = CouponClaim::factory()->for($campaign)->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/coupons/cancel', ['claim_id' => $claim->id])
            ->assertOk()
            ->assertExactJson(['claim_id' => $claim->id, 'campaign_id' => $campaign->id, 'status' => 'cancelled']);

        $this->assertSame(CouponClaimStatus::Cancelled, $claim->fresh()->status);
        $this->assertSame(0, $campaign->fresh()->claimed_quantity);
        $this->assertDatabaseHas('coupon_logs', [
            'coupon_claim_id' => $claim->id,
            'old_status' => 'claimed',
            'new_status' => 'cancelled',
            'actor_id' => $user->id,
        ]);

        // The released slot can be claimed by someone else.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/coupons/claim', ['campaign_id' => $campaign->id])->assertCreated();
        $this->assertSame(1, $campaign->fresh()->claimed_quantity);
    }

    public function test_user_cannot_cancel_someone_elses_claim(): void
    {
        $campaign = Campaign::factory()->withQuantity(1, 1)->create();
        $claim = CouponClaim::factory()->for($campaign)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/coupons/cancel', ['claim_id' => $claim->id])->assertNotFound();

        $this->assertSame(CouponClaimStatus::Claimed, $claim->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->claimed_quantity);
    }

    public function test_cancelling_twice_does_not_decrement_twice(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->withQuantity(5, 2)->create();
        $claim = CouponClaim::factory()->for($campaign)->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/coupons/cancel', ['claim_id' => $claim->id])->assertOk();
        $this->postJson('/api/coupons/cancel', ['claim_id' => $claim->id])
            ->assertConflict()
            ->assertJsonPath('error.code', 'invalid_claim_state');

        $this->assertSame(1, $campaign->fresh()->claimed_quantity);
    }

    public function test_redeemed_claim_cannot_be_cancelled(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->withQuantity(5, 1)->create();
        $claim = CouponClaim::factory()->for($campaign)->for($user)->redeemed()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/coupons/cancel', ['claim_id' => $claim->id])
            ->assertConflict()
            ->assertJsonPath('error.details.status', 'redeemed');

        $this->assertSame(1, $campaign->fresh()->claimed_quantity);
    }

    public function test_overdue_claim_cannot_be_cancelled_and_counter_is_untouched(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->withQuantity(5, 1)->create();
        $claim = CouponClaim::factory()->for($campaign)->for($user)->overdue()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/coupons/cancel', ['claim_id' => $claim->id])
            ->assertConflict()
            ->assertJsonPath('error.code', 'claim_expired');

        $this->assertSame(1, $campaign->fresh()->claimed_quantity);
    }

    public function test_validation_and_auth(): void
    {
        $this->postJson('/api/coupons/cancel', ['claim_id' => 1])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/coupons/cancel', [])->assertUnprocessable()->assertJsonValidationErrors('claim_id');
    }
}
