<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CouponClaimStatus;
use App\Enums\CouponRedemptionStatus;
use App\Exceptions\Coupon\CampaignNotActiveException;
use App\Exceptions\Coupon\CampaignNotFoundException;
use App\Exceptions\Coupon\ClaimExpiredException;
use App\Exceptions\Coupon\ClaimNotFoundException;
use App\Exceptions\Coupon\CouponQuotaExhaustedException;
use App\Exceptions\Coupon\DuplicateClaimException;
use App\Exceptions\Coupon\InvalidClaimStateException;
use App\Exceptions\Coupon\TransactionIdConflictException;
use App\Models\Campaign;
use App\Models\CouponClaim;
use App\Models\CouponLog;
use App\Models\CouponRedemption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * All coupon business rules and every write to the coupon tables live here, so
 * the HTTP layer, the scheduler and the tests share exactly one code path.
 *
 * CONCURRENCY RULES (read before changing anything):
 *
 *  1. Lock order is ALWAYS: campaigns row  →  coupon_claims row  →  coupon_redemptions row.
 *     Every method that touches campaigns.claimed_quantity follows it, so two
 *     transactions can never wait on each other in a cycle (no deadlock).
 *     redeem() never touches the campaign, so it only locks the claim row.
 *
 *  2. The FIRST statement inside every transaction is a locking read
 *     (SELECT ... FOR UPDATE). Under MySQL REPEATABLE READ, plain SELECTs read
 *     from a snapshot taken at the first plain SELECT of the transaction. By
 *     acquiring the lock first, that snapshot is guaranteed to include every
 *     transaction that committed before we got the lock.
 *
 *  3. After acquiring a lock, state is RE-CHECKED from the locked row. Never
 *     trust a value read before the lock was held.
 *
 *  4. claimed_quantity counts claims in status claimed|redeemed. It is only
 *     changed while the campaign row lock is held, and a CHECK constraint
 *     (0 <= claimed_quantity <= total_quantity) backs it at the DB level.
 *
 *  5. Transactions are retried up to TRANSACTION_ATTEMPTS times on deadlock /
 *     lock-wait timeout (Laravel does this natively). The closures therefore
 *     have no side effects outside the database.
 */
final class CouponService
{
    private const TRANSACTION_ATTEMPTS = 3;

    /** Upper bound on overdue claims expired inline by a single claim() call. */
    private const SELF_HEAL_BATCH = 100;

    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /**
     * POST /api/coupons/claim
     *
     * @throws CampaignNotFoundException|CampaignNotActiveException|DuplicateClaimException|CouponQuotaExhaustedException
     */
    public function claim(User $user, int $campaignId): CouponClaim
    {
        try {
            return $this->db->transaction(function () use ($user, $campaignId): CouponClaim {
                $now = $this->now();

                // Serialises every quota-changing operation for this campaign.
                // 500 simultaneous requests queue here one by one; each sees the
                // claimed_quantity committed by the one before it.
                $campaign = Campaign::query()->whereKey($campaignId)->lockForUpdate()->first();

                if ($campaign === null) {
                    throw new CampaignNotFoundException();
                }

                if (! $campaign->isActiveAt($now)) {
                    throw new CampaignNotActiveException();
                }

                $this->ensureUserHoldsNoClaim($campaign, $user, $now);

                if (! $campaign->hasAvailableSlot()) {
                    // Self-healing: if the scheduler is behind, overdue claims are
                    // still counted. Release them now before declaring "sold out".
                    $this->releaseOverdueSlots($campaign, $now);

                    if (! $campaign->hasAvailableSlot()) {
                        throw new CouponQuotaExhaustedException();
                    }
                }

                $claim = CouponClaim::query()->create([
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'claimed_at' => $now,
                    'expires_at' => $now->addHours(CouponClaim::VALIDITY_HOURS),
                    'status' => CouponClaimStatus::Claimed,
                ]);

                // UPDATE campaigns SET claimed_quantity = claimed_quantity + 1 (row already locked).
                $campaign->increment('claimed_quantity');

                $this->writeLog($claim, null, CouponClaimStatus::Claimed, $user->id, 'Coupon claimed.');

                return $claim;
            }, self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            // Backstop only: the campaign lock already serialises claims, but if any
            // future code path skips it, the unique index still refuses a 2nd holding claim.
            throw new DuplicateClaimException();
        }
    }

    /**
     * POST /api/coupons/redeem — idempotent on $transactionId.
     *
     * @throws ClaimNotFoundException|ClaimExpiredException|InvalidClaimStateException|TransactionIdConflictException
     */
    public function redeem(User $user, int $claimId, string $transactionId, ?string $orderId = null): RedemptionResult
    {
        try {
            return $this->db->transaction(function () use ($user, $claimId, $transactionId, $orderId): RedemptionResult {
                $now = $this->now();

                // Lock the claim row. Five rapid identical requests queue here; the
                // scheduler's expire() also needs this lock, so the two can never
                // both act on the same claim.
                $claim = CouponClaim::query()
                    ->whereKey($claimId)
                    ->where('user_id', $user->id) // ownership: other users' claims are "not found"
                    ->lockForUpdate()
                    ->first();

                if ($claim === null) {
                    throw new ClaimNotFoundException();
                }

                // Idempotency check comes BEFORE the state check: a replay of a
                // successful request must return the original result, not
                // "claim is already redeemed".
                $existing = CouponRedemption::query()->where('transaction_id', $transactionId)->first();

                if ($existing !== null) {
                    if ((int) $existing->coupon_claim_id === $claim->id) {
                        return new RedemptionResult($existing, replayed: true);
                    }

                    throw new TransactionIdConflictException();
                }

                if ($claim->status !== CouponClaimStatus::Claimed) {
                    throw new InvalidClaimStateException($claim->status, 'redeem');
                }

                // Evaluated AFTER the lock is held. If the scheduler expired the claim
                // while we waited, the status check above already rejected it; if the
                // scheduler has not run, the timestamp check rejects it here.
                if ($claim->isExpiredAt($now)) {
                    throw new ClaimExpiredException();
                }

                $redemption = CouponRedemption::query()->create([
                    'coupon_claim_id' => $claim->id,
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'status' => CouponRedemptionStatus::Completed,
                ]);

                $this->transition($claim, CouponClaimStatus::Redeemed, $user->id, 'Coupon redeemed. transaction_id='.$transactionId);

                return new RedemptionResult($redemption, replayed: false);
            }, self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            // Reached only when the SAME transaction_id is used concurrently on two
            // DIFFERENT claims (different row locks, so not serialised above). The
            // unique index lets exactly one win; this call lost. The transaction has
            // been rolled back, so this read sees the latest committed data.
            $existing = CouponRedemption::query()->where('transaction_id', $transactionId)->first();

            if ($existing !== null && (int) $existing->coupon_claim_id === $claimId) {
                return new RedemptionResult($existing, replayed: true);
            }

            if ($existing !== null) {
                throw new TransactionIdConflictException();
            }

            // Violation on coupon_redemptions.coupon_claim_id (claim already redeemed).
            $claim = CouponClaim::query()->whereKey($claimId)->first();

            if ($claim === null) {
                throw new ClaimNotFoundException();
            }

            throw new InvalidClaimStateException($claim->status, 'redeem');
        }
    }

    /**
     * POST /api/coupons/cancel — the owner gives the slot back.
     *
     * @throws ClaimNotFoundException|ClaimExpiredException|InvalidClaimStateException
     */
    public function cancel(User $user, int $claimId): CouponClaim
    {
        // campaign_id never changes, so it is safe to read it without a lock and
        // OUTSIDE the transaction (see rule 2 in the class docblock).
        $campaignId = $this->findCampaignIdForClaim($claimId, $user->id);

        return $this->db->transaction(function () use ($user, $claimId, $campaignId): CouponClaim {
            $now = $this->now();
            [$campaign, $claim] = $this->lockCampaignAndClaim($campaignId, $claimId);

            if ($claim->status !== CouponClaimStatus::Claimed) {
                throw new InvalidClaimStateException($claim->status, 'cancel');
            }

            if ($claim->isExpiredAt($now)) {
                // Its slot is released by the scheduler / self-healing path.
                throw new ClaimExpiredException();
            }

            $this->transition($claim, CouponClaimStatus::Cancelled, $user->id, 'Cancelled by user.');
            $campaign->decrement('claimed_quantity');

            return $claim;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * POST /api/admin/coupon/revoke — void a claimed OR redeemed coupon.
     *
     * @throws ClaimNotFoundException|InvalidClaimStateException
     */
    public function revoke(User $admin, int $claimId, string $reason): CouponClaim
    {
        $campaignId = $this->findCampaignIdForClaim($claimId, null);

        return $this->db->transaction(function () use ($admin, $claimId, $campaignId, $reason): CouponClaim {
            [$campaign, $claim] = $this->lockCampaignAndClaim($campaignId, $claimId);

            if (! $claim->status->holdsSlot()) {
                throw new InvalidClaimStateException($claim->status, 'revoke');
            }

            if ($claim->status === CouponClaimStatus::Redeemed) {
                // Third lock in the global order (campaign → claim → redemption).
                $redemption = CouponRedemption::query()
                    ->where('coupon_claim_id', $claim->id)
                    ->lockForUpdate()
                    ->first();

                if ($redemption !== null) {
                    $redemption->status = CouponRedemptionStatus::Voided;
                    $redemption->save();
                }
            }

            $this->transition($claim, CouponClaimStatus::Cancelled, $admin->id, 'Revoked by admin: '.$reason);
            $campaign->decrement('claimed_quantity');

            return $claim;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Used by the scheduler (coupons:expire). Each claim is expired in its own
     * short transaction so locks are held for milliseconds, not for the whole batch.
     *
     * @return bool true if this call expired the claim; false if it was no longer
     *              eligible (already redeemed / cancelled / expired by someone else).
     */
    public function expireOverdueClaim(int $claimId, int $campaignId): bool
    {
        return $this->db->transaction(function () use ($claimId, $campaignId): bool {
            $now = $this->now();

            $campaign = Campaign::query()->whereKey($campaignId)->lockForUpdate()->first();
            $claim = CouponClaim::query()->whereKey($claimId)->lockForUpdate()->first();

            if ($campaign === null || $claim === null) {
                return false;
            }

            return $this->expireIfOverdue($campaign, $claim, $now, 'Expired by scheduler.');
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * GET /api/coupons/my-active
     *
     * @return LengthAwarePaginator<int, CouponClaim>
     */
    public function activeClaimsFor(User $user, int $perPage): LengthAwarePaginator
    {
        return CouponClaim::query()
            ->where('user_id', $user->id)
            ->activeAt($this->now()) // lazy expiry: overdue claims are never shown as active
            ->with('campaign:id,name,code') // eager load: no N+1
            ->orderBy('expires_at')
            ->orderBy('id')
            ->paginate($perPage);
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Precondition: the campaign row is locked by the current transaction.
     */
    private function ensureUserHoldsNoClaim(Campaign $campaign, User $user, CarbonImmutable $now): void
    {
        // Plain read is sufficient: every transaction that can create/release a
        // claim for this campaign holds the campaign lock we already own.
        $existing = CouponClaim::query()
            ->where('campaign_id', $campaign->id)
            ->where('user_id', $user->id)
            ->whereIn('status', CouponClaimStatus::holdingValues())
            ->first();

        if ($existing === null) {
            return;
        }

        // The user's previous claim ran out but the scheduler hasn't marked it yet.
        // Without this, the unique index would wrongly block a legitimate re-claim.
        if ($existing->status === CouponClaimStatus::Claimed && $existing->isExpiredAt($now)) {
            $locked = CouponClaim::query()->whereKey($existing->id)->lockForUpdate()->first();

            if ($locked !== null && $this->expireIfOverdue($campaign, $locked, $now, 'Expired lazily on re-claim.')) {
                return;
            }
        }

        throw new DuplicateClaimException($existing->id);
    }

    /**
     * Precondition: the campaign row is locked by the current transaction.
     */
    private function releaseOverdueSlots(Campaign $campaign, CarbonImmutable $now): void
    {
        $overdueIds = CouponClaim::query()
            ->where('campaign_id', $campaign->id)
            ->overdueAt($now)
            ->orderBy('id')
            ->limit(self::SELF_HEAL_BATCH)
            ->pluck('id');

        foreach ($overdueIds as $id) {
            // Lock by primary key (record lock only, no gap lock) and re-check.
            $claim = CouponClaim::query()->whereKey($id)->lockForUpdate()->first();

            if ($claim !== null) {
                $this->expireIfOverdue($campaign, $claim, $now, 'Expired lazily: slot reclaimed during a claim.');
            }
        }
    }

    /**
     * Precondition: campaign AND claim rows are locked by the current transaction.
     */
    private function expireIfOverdue(Campaign $campaign, CouponClaim $claim, CarbonImmutable $now, string $remarks): bool
    {
        if ($claim->status !== CouponClaimStatus::Claimed || ! $claim->isExpiredAt($now)) {
            return false;
        }

        $this->transition($claim, CouponClaimStatus::Expired, null, $remarks);
        $campaign->decrement('claimed_quantity');

        return true;
    }

    /**
     * @return array{0: Campaign, 1: CouponClaim}
     */
    private function lockCampaignAndClaim(int $campaignId, int $claimId): array
    {
        // Order matters: campaign first, then claim (see class docblock, rule 1).
        $campaign = Campaign::query()->whereKey($campaignId)->lockForUpdate()->first();
        $claim = CouponClaim::query()->whereKey($claimId)->lockForUpdate()->first();

        if ($campaign === null || $claim === null) {
            throw new ClaimNotFoundException();
        }

        return [$campaign, $claim];
    }

    private function findCampaignIdForClaim(int $claimId, ?int $ownerId): int
    {
        $campaignId = CouponClaim::query()
            ->whereKey($claimId)
            ->when($ownerId !== null, fn ($query) => $query->where('user_id', $ownerId))
            ->value('campaign_id');

        if ($campaignId === null) {
            throw new ClaimNotFoundException();
        }

        return (int) $campaignId;
    }

    /**
     * The single place where a claim's status changes. Enforces the state machine
     * and writes the audit log in the same transaction as the change.
     */
    private function transition(CouponClaim $claim, CouponClaimStatus $to, ?int $actorId, string $remarks): void
    {
        $from = $claim->status;

        if (! $from->canTransitionTo($to)) {
            // Programming error, not a user error: callers must check first.
            throw new LogicException(sprintf('Illegal coupon claim transition %s → %s.', $from->value, $to->value));
        }

        $claim->status = $to;
        $claim->save();

        $this->writeLog($claim, $from, $to, $actorId, $remarks);
    }

    private function writeLog(
        CouponClaim $claim,
        ?CouponClaimStatus $from,
        CouponClaimStatus $to,
        ?int $actorId,
        string $remarks,
    ): void {
        CouponLog::query()->create([
            'coupon_claim_id' => $claim->id,
            'old_status' => $from,
            'new_status' => $to,
            'actor_id' => $actorId,
            'remarks' => mb_substr($remarks, 0, 500),
        ]);
    }

    /**
     * One clock for all comparisons, truncated to whole seconds because the
     * DATETIME columns store whole seconds. Using the same truncated value in
     * PHP comparisons and SQL bindings removes sub-second boundary mismatches.
     */
    private function now(): CarbonImmutable
    {
        return now()->toImmutable()->startOfSecond();
    }
}
