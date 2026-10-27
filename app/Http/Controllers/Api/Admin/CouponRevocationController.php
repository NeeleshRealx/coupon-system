<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coupon\RevokeCouponRequest;
use App\Http\Resources\ClaimStateResource;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;

class CouponRevocationController extends Controller
{
    public function __construct(private readonly CouponService $coupons)
    {
    }

    public function __invoke(RevokeCouponRequest $request): JsonResponse
    {
        $claim = $this->coupons->revoke(
            $request->user(),
            $request->integer('claim_id'),
            $request->string('reason')->trim()->toString(),
        );

        return ClaimStateResource::make($claim)->response();
    }
}
