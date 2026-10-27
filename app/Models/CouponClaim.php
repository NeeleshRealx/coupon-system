<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CouponClaimStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $campaign_id
 * @property int $user_id
 * @property \Carbon\CarbonImmutable $claimed_at
 * @property \Carbon\CarbonImmutable $expires_at
 * @property CouponClaimStatus $status
 */
class CouponClaim extends Model
{
    /** @use HasFactory<\Database\Factories\CouponClaimFactory> */
    use HasFactory;

    /** Validity of a claim, fixed by the business rules. */
    public const VALIDITY_HOURS = 24;

    /**
     * Claims are only created by CouponService; nothing is mass-assigned from requests.
     * holding_flag is a generated column and must never be written.
     */
    protected $fillable = [
        'campaign_id',
        'user_id',
        'claimed_at',
        'expires_at',
        'status',
    ];

    protected $hidden = ['holding_flag'];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'status' => CouponClaimStatus::class,
        ];
    }

    /**
     * A claim is expired from the instant expires_at is reached, whether or not
     * the scheduler has updated its status yet ("lazy expiry").
     */
    public function isExpiredAt(CarbonInterface $now): bool
    {
        return $now->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * Claims that are usable right now: status claimed and not yet past expiry.
     *
     * @param  Builder<CouponClaim>  $query
     */
    public function scopeActiveAt(Builder $query, CarbonInterface $now): void
    {
        $query->where('status', CouponClaimStatus::Claimed->value)
            ->where('expires_at', '>', $now);
    }

    /**
     * Claims whose time is up but whose status has not been updated yet.
     *
     * @param  Builder<CouponClaim>  $query
     */
    public function scopeOverdueAt(Builder $query, CarbonInterface $now): void
    {
        $query->where('status', CouponClaimStatus::Claimed->value)
            ->where('expires_at', '<=', $now);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<CouponRedemption, $this>
     */
    public function redemption(): HasOne
    {
        return $this->hasOne(CouponRedemption::class);
    }

    /**
     * @return HasMany<CouponLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(CouponLog::class);
    }
}
