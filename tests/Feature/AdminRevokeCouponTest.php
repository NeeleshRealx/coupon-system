<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CouponClaimStatus;
use App\Enums\CouponRedemptionStatus;
use App\Models\Campaign;
use App\Models\CouponClaim;
use App\Models\CouponRedemption;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminRevokeCouponTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_admin_revokes_a_claimed_coupon_releases_the_slot_and_logs_remarks(): void
    {
        $campaign = Campaign::factory()->withQuantity(1, 1)->create();
        $claim = CouponClaim::factory()->for($campaign)->create();
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/coupon/revoke', ['claim_id' => $claim->id, 'reason' => 'Fraud prevention'])
            ->assertOk()
            ->assertJson(['claim_id' => $claim->id, 'status' => 'cancelled']);

        $this->assertSame(CouponClaimStatus::Cancelled, $claim->fresh()->status);
        $this->assertSame(0, $campaign->fresh()->claimed_quantity);
        $this->assertDatabaseHas('coupon_logs', [
            'coupon_claim_id' => $claim->id,
            'old_status' => 'claimed',
            'new_status' => 'cancelled',
            'actor_id' => $this->admin->id,
            'remarks' => 'Revoked by admin: Fraud prevention',
        ]);
    }

    public function test_admin_revokes_a_redeemed_coupon_and_voids_the_redemption(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->withQuantity(5, 0)->create();
        $service = app(CouponService::class);
        $claim = $service->claim($user, $campaign->id);
        $service->redeem($user, $claim->id, 'TX-FRAUD');
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/coupon/revoke', ['claim_id' => $claim->id, 'reason' => 'Chargeback'])->assertOk();

        $this->assertSame(CouponClaimStatus::Cancelled, $claim->fresh()->status);
        $this->assertSame(CouponRedemptionStatus::Voided, CouponRedemption::query()->sole()->status);
        $this->assertSame(0, $campaign->fresh()->claimed_quantity);
        $this->assertDatabaseHas('coupon_logs', [
            'coupon_claim_id' => $claim->id,
            'old_status' => 'redeemed',
            'new_status' => 'cancelled',
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $campaign = Campaign::factory()->withQuantity(1, 1)->create();
        $claim = CouponClaim::factory()->for($campaign)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/admin/coupon/revoke', ['claim_id' => $claim->id, 'reason' => 'Nope'])->assertForbidden();

        $this->assertSame(CouponClaimStatus::Claimed, $claim->fresh()->status);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->postJson('/api/admin/coupon/revoke', ['claim_id' => 1, 'reason' => 'x'])->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function voidStates(): array
    {
        return ['expired' => ['expired'], 'cancelled' => ['cancelled']];
    }

    #[DataProvider('voidStates')]
    public function test_already_void_claim_cannot_be_revoked_again(string $state): void
    {
        $campaign = Campaign::factory()->withQuantity(5, 0)->create();
        $claim = CouponClaim::factory()->for($campaign)->{$state}()->create();
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/coupon/revoke', ['claim_id' => $claim->id, 'reason' => 'Again'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'invalid_claim_state');

        $this->assertSame(0, $campaign->fresh()->claimed_quantity);
    }

    public function test_unknown_claim_and_validation(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/coupon/revoke', ['claim_id' => 999999, 'reason' => 'Fraud'])->assertNotFound();
        $this->postJson('/api/admin/coupon/revoke', ['claim_id' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_is_admin_cannot_be_mass_assigned(): void
    {
        $user = new User(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'secret', 'is_admin' => true]);

        $this->assertNull($user->is_admin);
    }
}
