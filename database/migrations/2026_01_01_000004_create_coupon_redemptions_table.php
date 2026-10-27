<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            // UNIQUE: a claim can be redeemed at most once, even with different transaction ids.
            $table->foreignId('coupon_claim_id')->unique()->constrained()->restrictOnDelete();
            $table->string('order_id', 64)->nullable();
            // UNIQUE: the idempotency key. A second insert with the same key fails at the DB.
            $table->string('transaction_id', 64)->unique();
            $table->string('status', 20); // App\Enums\CouponRedemptionStatus
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
