<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CouponRedemptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $coupon_claim_id
 * @property string|null $order_id
 * @property string $transaction_id
 * @property CouponRedemptionStatus $status
 * @property \Carbon\CarbonImmutable $created_at
 */
class CouponRedemption extends Model
{
    protected $fillable = [
        'coupon_claim_id',
        'order_id',
        'transaction_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => CouponRedemptionStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CouponClaim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(CouponClaim::class, 'coupon_claim_id');
    }
}
