<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CouponClaimStatus as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CouponClaimStatusTest extends TestCase
{
    /**
     * Full transition matrix (4 x 4 = 16 cases).
     *
     * @return iterable<string, array{0: S, 1: S, 2: bool}>
     */
    public static function transitions(): iterable
    {
        $allowed = [
            'claimed->redeemed', 'claimed->expired', 'claimed->cancelled',
            'redeemed->cancelled',
        ];

        foreach (S::cases() as $from) {
            foreach (S::cases() as $to) {
                $key = $from->value.'->'.$to->value;
                yield $key => [$from, $to, in_array($key, $allowed, true)];
            }
        }
    }

    #[DataProvider('transitions')]
    public function test_state_machine(S $from, S $to, bool $expected): void
    {
        $this->assertSame($expected, $from->canTransitionTo($to));
    }

    public function test_only_claimed_and_redeemed_hold_a_slot(): void
    {
        $this->assertTrue(S::Claimed->holdsSlot());
        $this->assertTrue(S::Redeemed->holdsSlot());
        $this->assertFalse(S::Expired->holdsSlot());
        $this->assertFalse(S::Cancelled->holdsSlot());
        $this->assertSame(['claimed', 'redeemed'], S::holdingValues());
    }
}
