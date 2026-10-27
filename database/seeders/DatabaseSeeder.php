<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local/demo data only. Prints API tokens so the endpoints can be tried with curl.
 * Never run in production.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);

        $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

        Campaign::factory()->create([
            'name' => 'Launch Week — First 100',
            'code' => 'LAUNCH100',
            'total_quantity' => 100,
            'starts_at' => now()->subHour(),
            'expires_at' => now()->addDays(7),
            'status' => CampaignStatus::Active,
        ]);

        Campaign::factory()->create([
            'name' => 'Last Coupon Demo',
            'code' => 'LASTONE',
            'total_quantity' => 1,
            'starts_at' => now()->subHour(),
            'expires_at' => now()->addDays(7),
            'status' => CampaignStatus::Active,
        ]);

        $this->command?->info('Demo API tokens (Authorization: Bearer <token>):');
        $this->command?->line('  admin: '.$admin->createToken('demo')->plainTextToken);
        $this->command?->line('  alice: '.$alice->createToken('demo')->plainTextToken);
        $this->command?->line('  bob:   '.$bob->createToken('demo')->plainTextToken);
    }
}
