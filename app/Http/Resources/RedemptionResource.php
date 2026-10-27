<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CouponRedemption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CouponRedemption
 */
class RedemptionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'redemption_id' => $this->id,
            'claim_id' => $this->coupon_claim_id,
            'transaction_id' => $this->transaction_id,
            'order_id' => $this->order_id,
            'status' => $this->status->value,
            'redeemed_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
