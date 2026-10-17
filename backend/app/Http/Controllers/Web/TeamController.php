<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Custody;
use App\Models\Device;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use App\Services\RevokedTokens;
use App\Support\BusinessReference;
use App\Support\TenantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantAccess $tenantAccess,
        private readonly RevokedTokens $revokedTokens,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        // A branch-bound supervisor sees, and manages, their own branch's staff
        // only — the same boundary as every record they read.
        $users = $this->tenantAccess->scopeUsersToActor(User::query(), $actor);

        return view('panel.team', [
            'users' => (clone $users)->orderBy('role')->orderBy('name')->paginate(30, ['*'], 'users_page')->withQueryString(),
            // The custody form needs every active technician, not only the current page.
            'technicianOptions' => (clone $users)->where('role', User::ROLE_TECHNICIAN)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'devices' => Device::with('user')
                ->when(! $actor->isPlatformAdmin(), fn ($query) => $query->whereHas('user', fn ($user) => $this->tenantAccess->scopeUsersToActor($user, $actor)))
                ->latest('last_seen_at')->latest('id')->paginate(30, ['*'], 'devices_page')->withQueryString(),
            'custodies' => Custody::with(['user', 'vehicle', 'stockLocation'])->latest('id')->paginate(30, ['*'], 'custodies_page')->withQueryString(),
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
        $this->tenantAccess->assertUser($request->user(), User::query()->findOrFail($data['user_id']));
        if (isset($data['vehicle_id'])) {
            Vehicle::query()->findOrFail($data['vehicle_id']);
        }
        if (isset($data['stock_location_id'])) {
            StockLocation::query()->findOrFail($data['stock_location_id']);
        }
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
        $this->tenantAccess->assertManageableUser($request->user(), $user);

        $user->forceFill([
            'is_active' => ! $user->is_active,
            'auth_version' => (int) $user->auth_version + 1,
            'remember_token' => Str::random(60),
        ])->save();

        // Deactivating has to cut the sessions too. Leaving the tokens alive meant
        // a "disabled" technician kept syncing from a phone already in their hand
        // — the flag changed and nothing else did.
        if (! $user->is_active) {
            // Tombstoned, not just deleted: the phone must hear USER_INACTIVE and
            // wipe, not a 401 that reads as "sign in again, keep the data".
            $this->revokedTokens->revoke($user->tokens(), RevokedTokens::USER_INACTIVE);
            DB::table('sessions')->where('user_id', $user->id)->delete();
        } else {
            $this->revokedTokens->forgetForUser($user->id, RevokedTokens::USER_INACTIVE);
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
        $this->tenantAccess->assertManageableDevice($request->user(), $device);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:190']]);

        $device->forceFill([
            'revoked_at' => CarbonImmutable::now(),
            'revoked_reason' => $data['reason'] ?? 'أُبطل من اللوحة',
        ])->save();

        if ($device->user !== null) {
            $this->revokedTokens->revoke(
                $device->user->tokens()->where('name', 'device:'.$device->device_uuid),
                RevokedTokens::DEVICE_REVOKED,
            );
        }

        $this->audit->record('device.revoked', $device, null, ['reason' => $device->revoked_reason]);

        return back()->with('ok', 'أُبطل الجهاز وسقط رمزه فوراً.');
    }
}
