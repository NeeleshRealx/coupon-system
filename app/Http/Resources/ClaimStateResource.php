<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CouponClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Response for cancel / revoke.
 *
 * @mixin CouponClaim
 */
class ClaimStateResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'claim_id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'status' => $this->status->value,
        ];
    }
}
