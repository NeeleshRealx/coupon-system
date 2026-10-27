<?php

declare(strict_types=1);

namespace App\Http\Requests\Coupon;

use Illuminate\Foundation\Http\FormRequest;

class RevokeCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Defence in depth: the route also has the `can:revoke-coupons` middleware.
        return $this->user()?->can('revoke-coupons') ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'claim_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
