<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Custody;
use App\Models\Device;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use App\Support\BusinessReference;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('panel.team', [
            'users' => User::orderBy('role')->orderBy('name')->get(),
            'devices' => Device::with('user')->latest('last_seen_at')->get(),
            'custodies' => Custody::with(['user', 'vehicle', 'stockLocation'])->latest('id')->get(),
            'vehicles' => Vehicle::with('stockLocation')->where('is_active', true)->get(),
        ]);
    }

    public function issueCustody(Request $request): RedirectResponse
    {
        $this->requireOwner($request);
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'], 'item_type' => ['required', 'in:vehicle,device,tool,stock'],
            'item_name' => ['required', 'string', 'max:190'], 'serial_number' => ['nullable', 'string', 'max:96'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'], 'stock_location_id' => ['nullable', 'exists:stock_locations,id'],
            'condition_out' => ['required', 'string', 'max:1000'],
        ]);
        $custody = Custody::create($data + [
            'custody_no' => BusinessReference::make('CUS'),
            'issued_at' => now(), 'status' => 'issued', 'issued_by' => $request->user()->id,
        ]);
        if ($custody->vehicle_id) {
            Vehicle::whereKey($custody->vehicle_id)->update(['assigned_user_id' => $custody->user_id]);
        }
        $this->audit->record('custody.issued', $custody, null, $custody->only(['user_id', 'vehicle_id', 'item_type']));

        return back()->with('ok', "أُصدرت العهدة {$custody->custody_no}.");
    }

    public function acceptCustody(Request $request, Custody $custody): RedirectResponse
    {
        abort_unless($request->user()->isOwner() || $request->user()->id === $custody->user_id, 403);
        abort_unless($custody->status === 'issued', 422);
        $data = $request->validate(['accepted_name' => ['nullable', 'string', 'max:190']]);
        $acceptedName = $data['accepted_name'] ?? $request->user()->name;
        $hash = hash('sha256', implode('|', [$custody->id, $custody->user_id, $acceptedName, now()->toIso8601String(), hash('sha256', (string) $request->ip())]));
        $custody->forceFill(['accepted_at' => now(), 'status' => 'accepted', 'accepted_name' => $acceptedName, 'accepted_signature_hash' => $hash])->save();
        $this->audit->record('custody.accepted', $custody);

        return back()->with('ok', 'تم توثيق استلام العهدة.');
    }

    public function returnCustody(Request $request, Custody $custody): RedirectResponse
    {
        $this->requireOwner($request);
        $data = $request->validate(['condition_in' => ['required', 'string', 'max:1000'], 'loss_amount' => ['nullable', 'numeric', 'min:0'], 'loss_note' => ['nullable', 'string', 'max:1000']]);
        if ((float) ($data['loss_amount'] ?? 0) > 0 && blank($data['loss_note'] ?? null)) {
            return back()->with('err', 'يجب توضيح سبب الفاقد عند تسجيل قيمة فاقد.');
        }
        abort_if($custody->status === 'returned', 422);
        $custody->forceFill(['condition_in' => $data['condition_in'], 'loss_amount' => $data['loss_amount'] ?? 0, 'loss_note' => $data['loss_note'] ?? null, 'returned_at' => now(), 'returned_to' => $request->user()->id, 'status' => 'returned'])->save();
        if ($custody->vehicle_id) {
            Vehicle::whereKey($custody->vehicle_id)->where('assigned_user_id', $custody->user_id)->update(['assigned_user_id' => null]);
        }
        $this->audit->record('custody.returned', $custody, null, ['condition_in' => $data['condition_in']]);

        return back()->with('ok', 'تم إرجاع العهدة وتسجيل حالتها.');
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
            'password' => [
                'required',
                'string',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
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
            'operating_company_id' => $request->user()->operating_company_id,
            'operating_branch_id' => $request->user()->operating_branch_id,
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

        $device->user?->tokens()->where('name', 'device:'.$device->device_uuid)->delete();

        $this->audit->record('device.revoked', $device, null, ['reason' => $device->revoked_reason]);

        return back()->with('ok', 'أُبطل الجهاز وسقط رمزه فوراً.');
    }
}
