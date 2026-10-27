<?php

declare(strict_types=1);

namespace App\Http\Requests\Coupon;

use Illuminate\Foundation\Http\FormRequest;

class RedeemCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in CouponService::redeem()
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'claim_id' => ['required', 'integer', 'min:1'],
            // Idempotency key: bounded length + safe charset (stored in a unique index).
            'transaction_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            // Optional extension to the documented payload (see README "Assumptions").
            'order_id' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:\-]+$/'],
        ];
    }
}
