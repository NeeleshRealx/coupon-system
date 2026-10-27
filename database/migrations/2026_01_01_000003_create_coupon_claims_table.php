<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->dateTime('claimed_at');
            $table->dateTime('expires_at');
            $table->string('status', 20); // App\Enums\CouponClaimStatus
            $table->timestamps();

            // 1 while the claim occupies a slot (claimed|redeemed), NULL otherwise.
            // Unique indexes allow many NULLs, so a user may have any number of
            // expired/cancelled claims but at most ONE holding claim per campaign.
            // This is the database-level guarantee behind "no duplicate claims".
            $table->unsignedTinyInteger('holding_flag')
                ->nullable()
                ->storedAs("CASE WHEN status IN ('claimed', 'redeemed') THEN 1 ELSE NULL END");

            $table->unique(['campaign_id', 'user_id', 'holding_flag'], 'coupon_claims_one_holding_per_user');

            // Scheduler scan: WHERE status = 'claimed' AND expires_at <= ? ORDER BY id
            $table->index(['status', 'expires_at'], 'coupon_claims_status_expires_idx');
            // Self-healing scan inside claim(): WHERE campaign_id = ? AND status = 'claimed' AND expires_at <= ?
            $table->index(['campaign_id', 'status', 'expires_at'], 'coupon_claims_campaign_status_expires_idx');
            // GET /my-active: WHERE user_id = ? AND status = 'claimed' AND expires_at > ?
            // (also serves the user_id foreign key)
            $table->index(['user_id', 'status', 'expires_at'], 'coupon_claims_user_status_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_claims');
    }
};
