<?php

declare(strict_types=1);

namespace App\Http\Requests\Coupon;

use Illuminate\Foundation\Http\FormRequest;

class CancelClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in CouponService::cancel()
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'claim_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
