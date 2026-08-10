<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): View
    {
        return view('panel.team', [
            'users' => User::orderBy('role')->orderBy('name')->get(),
            'devices' => Device::with('user')->latest('last_seen_at')->get(),
        ]);
    }

    /**
     * Team management and device revocation are owner-only, matching the API.
     *
     * The panel had no distinction at all, so an 'admin' account could revoke a
     * technician's device and create users — actions the API explicitly reserves
     * for the owner. Two surfaces disagreeing about who may do what is how a
     * privilege boundary quietly becomes decorative.
     */
    private function requireOwner(Request $request): void
    {
        abort_unless($request->user()->isOwner(), 403, 'هذه العملية للمالك فقط.');
    }

    public function storeTechnician(Request $request): RedirectResponse
    {
        $this->requireOwner($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:32'],
            // The profession exactly as written on the work permit. The system
            // records it; it does not interpret labour law.
            'trade' => ['nullable', 'string', 'max:64'],
            'specialties' => ['nullable', 'array'],
            'shift_start' => ['required'],
            'shift_end' => ['required'],
        ]);

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => User::ROLE_TECHNICIAN,
            'phone' => $data['phone'] ?? null,
            'trade' => $data['trade'] ?? null,
            'specialties' => $data['specialties'] ?? [],
            'shift_start' => $data['shift_start'],
            'shift_end' => $data['shift_end'],
            'is_active' => true,
        ]);

        return back()->with('ok', 'أُضيف الفني. سجّل دخوله من التطبيق لربط جهازه.');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->requireOwner($request);

        $user->forceFill(['is_active' => ! $user->is_active])->save();

        // Deactivating has to cut the sessions too. Leaving the tokens alive meant
        // a "disabled" technician kept syncing from a phone already in their hand
        // — the flag changed and nothing else did.
        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return back()->with('ok', $user->is_active ? 'أُعيد تفعيل الحساب.' : 'أُوقف الحساب.');
    }

    /**
     * A lost handset carries client data, photos and signatures. Revoking it kills
     * the token immediately without locking the person out of a replacement phone.
     */
    public function revokeDevice(Request $request, Device $device): RedirectResponse
    {
        $this->requireOwner($request);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:190']]);

        $device->forceFill([
            'revoked_at' => CarbonImmutable::now(),
            'revoked_reason' => $data['reason'] ?? 'أُبطل من اللوحة',
        ])->save();

        $device->user?->tokens()->where('name', 'device:' . $device->device_uuid)->delete();

        $this->audit->record('device.revoked', $device, null, ['reason' => $device->revoked_reason]);

        return back()->with('ok', 'أُبطل الجهاز وسقط رمزه فوراً.');
    }
}
