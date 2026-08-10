<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The conditional close gate. A visit cannot be completed while anything required
 * is missing, and the response always carries the full list of what is missing —
 * "rejected" without a reason is useless to a technician standing in a kitchen.
 */
class VisitCloseBlocked extends RuntimeException
{
    /** @param array<int, array{code:string, message_ar:string, message_en:string, ref?:mixed}> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('Visit cannot be closed: ' . count($blockers) . ' item(s) missing.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'VISIT_CLOSE_BLOCKED',
            'message' => $this->getMessage(),
            'blockers' => $this->blockers,
        ], 422);
    }
}
