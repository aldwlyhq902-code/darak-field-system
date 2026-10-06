<?php

namespace App\Http\Resources\Api;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in account as the app sees it (contract C5): identity, role and
 * the scheduling facts the phone uses. Never cost, permissions or MFA state.
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'trade' => $user->trade,
            'specialties' => $user->specialties,
            'shift' => ['start' => $user->shift_start, 'end' => $user->shift_end],
            'server_time' => CarbonImmutable::now()->toIso8601String(),
        ];
    }
}
