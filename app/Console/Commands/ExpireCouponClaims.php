<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CouponClaim;
use App\Services\CouponService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class ExpireCouponClaims extends Command
{
    protected $signature = 'coupons:expire {--chunk=500 : Number of overdue claims fetched per query}';

    protected $description = 'Mark overdue coupon claims as expired and release their campaign slots';

    public function handle(CouponService $coupons): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $now = now()->startOfSecond();
        $expired = 0;
        $failed = 0;

        // chunkById pages by "id > last id", so rows changing status mid-run
        // (and dropping out of the WHERE) cannot make it skip or repeat rows.
        CouponClaim::query()
            ->overdueAt($now)
            ->select(['id', 'campaign_id'])
            ->chunkById($chunk, function (Collection $claims) use ($coupons, &$expired, &$failed): void {
                foreach ($claims as $claim) {
                    try {
                        // Own short transaction per claim; re-checks status under lock,
                        // so a concurrent redeem/cancel simply makes this a no-op.
                        if ($coupons->expireOverdueClaim($claim->id, $claim->campaign_id)) {
                            $expired++;
                        }
                    } catch (Throwable $e) {
                        // One bad row must not stop the batch; it is retried next run.
                        $failed++;
                        report($e);
                    }
                }
            });

        $this->info(sprintf('Expired %d coupon claim(s); %d failed.', $expired, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
