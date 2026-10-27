<?php

declare(strict_types=1);

namespace App\Http\Requests\Coupon;

use Illuminate\Foundation\Http\FormRequest;

class ClaimCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // any authenticated user (route is behind auth:sanctum)
    }

    /**
     * Existence is NOT validated here: the service looks the campaign up under a
     * row lock anyway, and returns 404 if it is missing. Validating with `exists`
     * would cost an extra query that could also be stale by the time we lock.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'campaign_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
