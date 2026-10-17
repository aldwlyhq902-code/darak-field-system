<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A request refused by a business rule: not enough stock in the sender's van,
 * a transfer already handled, a stocktake already closed.
 *
 * These used to be plain RuntimeExceptions, which the API rendered as a bare
 * 500. The phone reads 5xx as "server down, try again", so a routine refusal
 * was retried forever, its Arabic reason never reached the technician, and the
 * stuck action blocked sign-out. Over the API it is now 409 with a stable code
 * and the message verbatim. The panel catches RuntimeException as before.
 */
class BusinessRuleViolation extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! (getenv('VERCEL') && $request->is('v1/*'))) {
            return null;
        }

        return response()->json([
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->status);
    }
}
