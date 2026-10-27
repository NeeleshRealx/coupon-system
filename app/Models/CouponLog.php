<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CouponClaimStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable audit record of a single claim state transition.
 *
 * @property int $id
 * @property int $coupon_claim_id
 * @property CouponClaimStatus|null $old_status
 * @property CouponClaimStatus $new_status
 * @property string|null $remarks
 * @property int|null $actor_id
 * @property \Carbon\CarbonImmutable $created_at
 */
class CouponLog extends Model
{
    /** Only created_at exists; Eloquent must not try to write updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'coupon_claim_id',
        'old_status',
        'new_status',
        'remarks',
        'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'old_status' => CouponClaimStatus::class,
            'new_status' => CouponClaimStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Application-level immutability. (Database-level hardening — revoking
     * UPDATE/DELETE grants on this table — is described in the README.)
     */
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Coupon logs are immutable and cannot be updated.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Coupon logs are immutable and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<CouponClaim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(CouponClaim::class, 'coupon_claim_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
