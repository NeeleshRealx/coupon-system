<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit trail. No updated_at column on purpose.
        Schema::create('coupon_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_claim_id')->constrained()->restrictOnDelete();
            $table->string('old_status', 20)->nullable(); // NULL for the initial "created" transition
            $table->string('new_status', 20);
            $table->string('remarks', 500)->nullable();
            // Who caused the transition: the user, an admin, or NULL for the system (scheduler).
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at');

            $table->index(['coupon_claim_id', 'id'], 'coupon_logs_claim_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_logs');
    }
};
