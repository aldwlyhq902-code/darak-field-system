<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Custody;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustodyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Custody::with(['vehicle', 'stockLocation'])->where('user_id', $request->user()->id)->latest()->get()]);
    }

    public function accept(Request $request, Custody $custody): JsonResponse
    {
        abort_unless($custody->user_id === $request->user()->id, 403);
        abort_unless($custody->status === 'issued', 422);
        $data = $request->validate(['accepted_name' => ['required', 'string', 'max:190']]);
        $now = now();
        $hash = hash('sha256', implode('|', [$custody->id, $request->user()->id, $data['accepted_name'], $now->toIso8601String(), hash('sha256', (string) $request->ip())]));
        $custody->forceFill(['status' => 'accepted', 'accepted_at' => $now, 'accepted_name' => $data['accepted_name'], 'accepted_signature_hash' => $hash])->save();
        $this->audit->record('custody.accepted', $custody, null, ['signature_hash' => $hash], $request->user()->id);

        return response()->json(['data' => $custody]);
    }
}
