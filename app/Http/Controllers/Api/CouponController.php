<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coupon\CancelClaimRequest;
use App\Http\Requests\Coupon\ClaimCouponRequest;
use App\Http\Requests\Coupon\MyActiveCouponsRequest;
use App\Http\Requests\Coupon\RedeemCouponRequest;
use App\Http\Resources\ActiveClaimResource;
use App\Http\Resources\ClaimResource;
use App\Http\Resources\ClaimStateResource;
use App\Http\Resources\RedemptionResource;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin HTTP adapter: validate (FormRequest) → call CouponService → shape the
 * response (API Resource). Business-rule failures are domain exceptions that
 * render themselves as JSON (App\Exceptions\Coupon\CouponException::render).
 */
class CouponController extends Controller
{
    public function __construct(private readonly CouponService $coupons)
    {
    }

    public function claim(ClaimCouponRequest $request): JsonResponse
    {
        $claim = $this->coupons->claim($request->user(), $request->integer('campaign_id'));

        return ClaimResource::make($claim)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function redeem(RedeemCouponRequest $request): JsonResponse
    {
        $result = $this->coupons->redeem(
            $request->user(),
            $request->integer('claim_id'),
            $request->string('transaction_id')->toString(),
            $request->filled('order_id') ? $request->string('order_id')->toString() : null,
        );

        // 201 the first time; 200 + Idempotent-Replayed header for a safe replay.
        return RedemptionResource::make($result->redemption)
            ->response()
            ->setStatusCode($result->replayed ? Response::HTTP_OK : Response::HTTP_CREATED)
            ->header('Idempotent-Replayed', $result->replayed ? 'true' : 'false');
    }

    public function cancel(CancelClaimRequest $request): JsonResponse
    {
        $claim = $this->coupons->cancel($request->user(), $request->integer('claim_id'));

        return ClaimStateResource::make($claim)->response();
    }

    public function myActive(MyActiveCouponsRequest $request): AnonymousResourceCollection
    {
        return ActiveClaimResource::collection(
            $this->coupons->activeClaimsFor($request->user(), $request->perPage())
        );
    }
}
