<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CouponClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CouponClaim
 */
class ActiveClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Server-calculated so every client agrees; clients should still treat
        // expires_at as the source of truth (latency makes this approximate).
        $remainingSeconds = max(0, now()->startOfSecond()->diffInSeconds($this->expires_at, false));

        return [
            'claim_id' => $this->id,
            'status' => $this->status->value,
            'campaign' => [
                'id' => $this->campaign->id,
                'name' => $this->campaign->name,
                'code' => $this->campaign->code,
            ],
            'claimed_at' => $this->claimed_at->toIso8601ZuluString(),
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
            'remaining_seconds' => (int) $remainingSeconds,
        ];
    }
}
