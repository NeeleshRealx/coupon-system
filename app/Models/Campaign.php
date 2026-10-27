<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $total_quantity
 * @property int $claimed_quantity
 * @property \Carbon\CarbonImmutable $starts_at
 * @property \Carbon\CarbonImmutable $expires_at
 * @property CampaignStatus $status
 */
class Campaign extends Model
{
    /** @use HasFactory<\Database\Factories\CampaignFactory> */
    use HasFactory;

    /**
     * claimed_quantity is intentionally NOT fillable: it is only changed by
     * CouponService under a row lock.
     */
    protected $fillable = [
        'name',
        'code',
        'total_quantity',
        'starts_at',
        'expires_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'total_quantity' => 'integer',
            'claimed_quantity' => 'integer',
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'status' => CampaignStatus::class,
        ];
    }

    /**
     * Active window is half-open: [starts_at, expires_at).
     */
    public function isActiveAt(CarbonInterface $now): bool
    {
        return $this->status === CampaignStatus::Active
            && $now->greaterThanOrEqualTo($this->starts_at)
            && $now->lessThan($this->expires_at);
    }

    public function hasAvailableSlot(): bool
    {
        return $this->claimed_quantity < $this->total_quantity;
    }

    /**
     * @return HasMany<CouponClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(CouponClaim::class);
    }
}
