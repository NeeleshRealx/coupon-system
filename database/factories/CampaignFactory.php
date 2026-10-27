<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true).' Promo',
            'code' => strtoupper(Str::random(10)),
            'total_quantity' => 100,
            'claimed_quantity' => 0,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(7),
            'status' => CampaignStatus::Active,
        ];
    }

    public function withQuantity(int $total, int $claimed = 0): static
    {
        return $this->state(fn (): array => [
            'total_quantity' => $total,
            'claimed_quantity' => $claimed,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['status' => CampaignStatus::Paused]);
    }

    public function upcoming(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->addDay(),
            'expires_at' => now()->addDays(8),
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subDays(8),
            'expires_at' => now()->subDay(),
        ]);
    }
}
