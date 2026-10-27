<?php

declare(strict_types=1);

namespace App\Exceptions\Coupon;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Base class for expected business-rule failures. These are NOT bugs: they are
 * rendered as clean 4xx JSON and are excluded from error reporting
 * (see bootstrap/app.php).
 */
abstract class CouponException extends RuntimeException
{
    /** Machine-readable error code clients can branch on. */
    abstract public function errorCode(): string;

    abstract public function httpStatus(): int;

    /**
     * Extra, safe-to-expose context for the client.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [];
    }

    /**
     * Laravel calls render() automatically for exceptions that define it.
     */
    public function render(): JsonResponse
    {
        $error = ['code' => $this->errorCode(), 'message' => $this->getMessage()];

        if ($this->details() !== []) {
            $error['details'] = $this->details();
        }

        return new JsonResponse(['error' => $error], $this->httpStatus());
    }
}
