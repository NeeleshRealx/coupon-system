<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CouponClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exact shape required by the spec: {"claim_id": 12, "expires_at": "2026-06-17T11:41:22Z"}
 *
 * @mixin CouponClaim
 */
class ClaimResource extends JsonResource
{
    /** Spec response is not wrapped in "data". */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'claim_id' => $this->id,
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
        ];
    }
}
