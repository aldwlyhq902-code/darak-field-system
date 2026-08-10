<?php

namespace App\Exceptions;

use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Returned as HTTP 409 with a stable error code so the mobile client can react
 * without string-matching a message (acceptance criterion AC-03).
 */
class InvalidVisitTransition extends RuntimeException
{
    public function __construct(
        public readonly string $fromState,
        public readonly string $toState,
        public readonly array $allowed = [],
    ) {
        parent::__construct("Cannot move visit from [{$fromState}] to [{$toState}].");
    }

    public static function for(Visit $visit, string $target): self
    {
        return new self(
            $visit->state,
            $target,
            Visit::TRANSITIONS[$visit->state] ?? [],
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'INVALID_VISIT_TRANSITION',
            'message' => $this->getMessage(),
            'from' => $this->fromState,
            'to' => $this->toState,
            'allowed' => $this->allowed,
        ], 409);
    }
}
