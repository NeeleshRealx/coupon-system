<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 64)->unique();
            $table->unsignedInteger('total_quantity');
            // Number of slots currently held by claims in status claimed|redeemed.
            // Only ever changed inside a transaction that holds this row's lock.
            $table->unsignedInteger('claimed_quantity')->default(0);
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');
            $table->string('status', 20)->default('draft'); // App\Enums\CampaignStatus
            $table->timestamps();
        });

        // Last line of defence against oversubscription: even a buggy code path
        // cannot persist claimed_quantity > total_quantity (or a negative count).
        // MySQL enforces CHECK constraints from 8.0.16; SQLite cannot add them via ALTER.
        if (in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            DB::statement(
                'ALTER TABLE campaigns ADD CONSTRAINT campaigns_quantity_check '
                .'CHECK (claimed_quantity >= 0 AND claimed_quantity <= total_quantity)'
            );
            DB::statement(
                'ALTER TABLE campaigns ADD CONSTRAINT campaigns_window_check CHECK (expires_at > starts_at)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
