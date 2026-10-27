<?php

use App\Http\Controllers\Api\Admin\CouponRevocationController;
use App\Http\Controllers\Api\CouponController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::prefix('coupons')->group(function (): void {
        // Write endpoints share a per-user limiter (see AppServiceProvider).
        Route::middleware('throttle:coupon-writes')->group(function (): void {
            Route::post('claim', [CouponController::class, 'claim'])->name('coupons.claim');
            Route::post('redeem', [CouponController::class, 'redeem'])->name('coupons.redeem');
            Route::post('cancel', [CouponController::class, 'cancel'])->name('coupons.cancel');
        });

        Route::get('my-active', [CouponController::class, 'myActive'])
            ->middleware('throttle:api')
            ->name('coupons.my-active');
    });

    Route::post('admin/coupon/revoke', CouponRevocationController::class)
        ->middleware(['can:revoke-coupons', 'throttle:api'])
        ->name('admin.coupons.revoke');
});
