<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CouponClaim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class MyActiveCouponsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-16 10:00:00', 'UTC'));
    }

    public function test_returns_only_the_users_usable_claims_with_remaining_validity(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['name' => 'Summer', 'code' => 'SUMMER']);
        $active = CouponClaim::factory()->for($campaign)->for($user)->create();

        // Noise that must NOT be listed:
        CouponClaim::factory()->for($user)->redeemed()->create();
        CouponClaim::factory()->for($user)->cancelled()->create();
        CouponClaim::factory()->for($user)->expired()->create();
        CouponClaim::factory()->for($user)->overdue()->create(); // status "claimed" but time is up
        CouponClaim::factory()->create();                        // another user's claim

        Sanctum::actingAs($user);
        $this->travel(1)->hours();

        $this->getJson('/api/coupons/my-active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.claim_id', $active->id)
            ->assertJsonPath('data.0.status', 'claimed')
            ->assertJsonPath('data.0.campaign.code', 'SUMMER')
            ->assertJsonPath('data.0.expires_at', '2026-06-17T10:00:00Z')
            ->assertJsonPath('data.0.remaining_seconds', 23 * 3600)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_claim_disappears_from_the_list_at_the_exact_expiry_instant(): void
    {
        $user = User::factory()->create();
        CouponClaim::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->travelTo(CarbonImmutable::parse('2026-06-17 09:59:59', 'UTC'));
        $this->getJson('/api/coupons/my-active')->assertJsonCount(1, 'data')->assertJsonPath('data.0.remaining_seconds', 1);

        $this->travelTo(CarbonImmutable::parse('2026-06-17 10:00:00', 'UTC'));
        $this->getJson('/api/coupons/my-active')->assertJsonCount(0, 'data');
    }

    public function test_is_paginated_and_per_page_is_bounded(): void
    {
        $user = User::factory()->create();
        CouponClaim::factory()->count(3)->for($user)->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/coupons/my-active?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/coupons/my-active?per_page=51')->assertUnprocessable();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/coupons/my-active')->assertUnauthorized();
    }
}
