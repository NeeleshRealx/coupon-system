<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CouponLog;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class CouponLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeLog(): CouponLog
    {
        $campaign = Campaign::factory()->create();
        app(CouponService::class)->claim(User::factory()->create(), $campaign->id);

        return CouponLog::query()->sole();
    }

    public function test_log_cannot_be_updated(): void
    {
        $log = $this->makeLog();
        $log->remarks = 'tampered';

        $this->expectException(LogicException::class);
        $log->save();
    }

    public function test_log_cannot_be_deleted(): void
    {
        $log = $this->makeLog();

        $this->expectException(LogicException::class);
        $log->delete();
    }
}
