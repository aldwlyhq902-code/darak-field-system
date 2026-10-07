<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CommissionRule;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\FinancialApproval;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\SalesLead;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\Visit;
use App\Services\AuditLogger;
use App\Services\CommissionService;
use App\Services\FinancialApprovalService;
use App\Support\BranchAssignment;
use App\Support\BusinessReference;
use App\Support\TenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminOperationsController extends Controller
{
    public function __construct(private readonly CommissionService $commissions, private readonly FinancialApprovalService $approvals, private readonly AuditLogger $audit, private readonly TenantAccess $tenantAccess, private readonly BranchAssignment $branches) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        abort_if(! $actor->isPlatformAdmin() && $actor->operating_company_id === null, 403);
        $companyId = $actor->operating_company_id;

        $users = User::query()->when(! $actor->isPlatformAdmin(), fn ($query) => $query->where('operating_company_id', $companyId));

        // Growing lists are paginated with independent page names. Contracts,
        // visits and receipts are no longer preloaded into selects (they were
        // silently capped at 100): the commission form takes their reference.
        return view('panel.admin-operations', [
            'companies' => OperatingCompany::with('branches')->when(! $actor->isPlatformAdmin(), fn ($query) => $query->whereKey($companyId))->get(),
            'branches' => OperatingBranch::with('company')->when(! $actor->isPlatformAdmin(), fn ($query) => $query->where('operating_company_id', $companyId))->get(),
            'leads' => SalesLead::with(['owner', 'activities'])->latest()->paginate(25, ['*'], 'leads_page')->withQueryString(),
            // A select needs the whole (bounded) active fleet, never a truncated slice.
            'vehicles' => Vehicle::with('assignedUser')->where('is_active', true)->orderBy('plate')->get(),
            'expenses' => VehicleExpense::with('vehicle')->latest()->paginate(20, ['*'], 'expenses_page')->withQueryString(),
            // Company-owned (global scope); company-less platform defaults only reach platform admins.
            'rules' => CommissionRule::with('company')->latest()->get(),
            'approvals' => FinancialApproval::with(['requester', 'firstApprover', 'secondApprover'])
                ->when(! $actor->isPlatformAdmin(), fn ($query) => $query->whereHas('requester', fn ($user) => $user->where('operating_company_id', $companyId)))
                ->latest()->paginate(20, ['*'], 'approvals_page')->withQueryString(),
            'users' => (clone $users)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']),
            'permissionUsers' => (clone $users)->orderBy('name')->paginate(25, ['*'], 'users_page')->withQueryString(),
            'branchChoices' => $branchChoices = $this->branches->choices($actor),
            'branchDefault' => $this->branches->defaultChoice($actor, $branchChoices),
        ]);
    }

    public function organization(Request $request): View
    {
        $query = OperatingCompany::query()->withCount('branches')->oldest('id');
        if (! $request->user()->isPlatformAdmin()) {
            $companyId = $request->user()->operating_company_id;
            abort_unless($companyId !== null, 403);
            $query->whereKey($companyId);
        }

        return view('panel.organization', [
            'companies' => $query->get(),
        ]);
    }

    public function updateCompany(Request $request, OperatingCompany $company): RedirectResponse
    {
        $this->authorizeCompanyProfile($request, $company);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'cr_number' => ['nullable', 'string', 'max:32'],
            'vat_number' => ['nullable', 'string', 'max:32'],
            'currency' => ['required', 'string', 'size:3'],
            'phone' => ['nullable', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2400,max_height=2400'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        unset($data['logo'], $data['remove_logo']);
        $original = $company->getAttributes();
        $oldLogo = $company->logo_path;
        $newLogo = null;

        if ($request->hasFile('logo')) {
            $newLogo = $request->file('logo')->store('organization-logos', 'public');
            abort_if($newLogo === false, 500, 'تعذر حفظ الشعار.');
            $data['logo_path'] = $newLogo;
        } elseif ($request->boolean('remove_logo')) {
            $data['logo_path'] = null;
        }

        try {
            $company->fill($data)->save();
            $this->audit->recordChange('operating_company.profile_updated', $company, $original, $request->user()->id);
        } catch (\Throwable $exception) {
            if ($newLogo !== null) {
                Storage::disk('public')->delete($newLogo);
            }
            throw $exception;
        }

        if ($oldLogo !== null && $oldLogo !== $company->logo_path) {
            Storage::disk('public')->delete($oldLogo);
        }

        return back()->with('ok', 'حُفظت بيانات المؤسسة والشعار.');
    }

    public function companyLogo(Request $request, OperatingCompany $company): StreamedResponse
    {
        $this->authorizeCompanyProfile($request, $company);
        abort_unless($company->logo_path && Storage::disk('public')->exists($company->logo_path), 404);

        return Storage::disk('public')->response($company->logo_path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }

    private function authorizeCompanyProfile(Request $request, OperatingCompany $company): void
    {
        $this->tenantAccess->assertCompany($request->user(), $company, 403);
    }

    public function company(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);
        OperatingCompany::create($request->validate(['name' => ['required', 'string', 'max:190'], 'legal_name' => ['nullable', 'string', 'max:190'], 'cr_number' => ['nullable', 'string', 'max:32'], 'vat_number' => ['nullable', 'string', 'max:32'], 'currency' => ['required', 'string', 'size:3']]) + ['is_active' => true]);

        return back()->with('ok', 'أُضيفت الشركة التشغيلية.');
    }

    public function branch(Request $request): RedirectResponse
    {
        $data = $request->validate(['operating_company_id' => ['required', 'exists:operating_companies,id'], 'name' => ['required', 'string', 'max:190'], 'code' => ['required', 'string', 'max:32'], 'address' => ['nullable', 'string', 'max:500']]);
        $this->tenantAccess->assertCompany($request->user(), (int) $data['operating_company_id'], 403);
        OperatingBranch::create($data + ['is_active' => true]);

        return back()->with('ok', 'أُضيف الفرع التشغيلي.');
    }

    public function assignBranch(Request $request): RedirectResponse
    {
        $data = $request->validate(['entity_type' => ['required', 'in:user,client,vehicle'], 'entity_id' => ['required', 'integer'], 'operating_branch_id' => ['nullable', 'exists:operating_branches,id']]);
        $actor = $request->user();
        $branch = isset($data['operating_branch_id'])
            ? OperatingBranch::query()->findOrFail($data['operating_branch_id'])
            : null;
        abort_if(! $actor->isPlatformAdmin() && $actor->operating_company_id === null, 403);
        abort_if(
            ! $actor->isPlatformAdmin() && $branch !== null
            && (int) $branch->operating_company_id !== (int) $actor->operating_company_id,
            403,
        );
        // A branch-bound actor stays inside its branch: it can neither widen a
        // record to company-wide (no branch) nor move one into another branch.
        abort_if(
            ! $actor->isPlatformAdmin() && $actor->operating_branch_id !== null
            && (int) $branch?->id !== (int) $actor->operating_branch_id,
            403,
        );
        if ($data['entity_type'] === 'user') {
            // A user's branch is their data boundary. Only the owner moves people,
            // and nobody — the owner included — re-scopes their own account.
            abort_unless($actor->isOwner() || $actor->isPlatformAdmin(), 403, 'تغيير فرع المستخدمين صلاحية للمالك فقط.');
            abort_if((int) $data['entity_id'] === (int) $actor->id, 403, 'لا يمكن تغيير الفرع المرتبط بحسابك.');
        }
        // Users and clients may be company-wide; a vehicle (and its stock) is
        // only ever visible through its branch, so it must keep one.
        if ($data['entity_type'] === 'vehicle' && $branch === null) {
            return back()->withErrors(['operating_branch_id' => 'السيارة تتبع فرعًا دائمًا؛ اختر الفرع.'])->with('err', 'السيارة تتبع فرعًا دائمًا؛ اختر الفرع.');
        }
        $model = match ($data['entity_type']) {
            'user' => $this->tenantAccess->scopeUsersToActor(User::query(), $actor)->findOrFail($data['entity_id']),
            'client' => Client::findOrFail($data['entity_id']),
            'vehicle' => Vehicle::findOrFail($data['entity_id']),
        };
        $before = $model->getAttributes();
        $tenant = ['operating_branch_id' => $branch?->id];
        if ($model instanceof User || $model instanceof Client) {
            $tenant['operating_company_id'] = $branch?->operating_company_id
                ?? $model->operating_company_id
                ?? $actor->operating_company_id;
        }
        $model->forceFill($tenant)->save();
        if ($model instanceof Vehicle) {
            // Its mobile warehouse moves with it (unscoped: a branchless
            // location is exactly the one the scope would hide).
            StockLocation::withoutGlobalScopes()->where('vehicle_id', $model->id)->update(['operating_branch_id' => $branch->id]);
        }
        $this->audit->recordChange('tenancy.branch_assigned', $model, $before, $actor->id);

        return back()->with('ok', 'حُدث الفرع المرتبط بالسجل.');
    }

    public function lead(Request $request): RedirectResponse
    {
        $data = $request->validate(['company_name' => ['required', 'string', 'max:190'], 'contact_name' => ['nullable', 'string', 'max:190'], 'phone' => ['nullable', 'string', 'max:32'], 'email' => ['nullable', 'email'], 'stage' => ['required', 'in:new,qualified,proposal,negotiation,won,lost'], 'estimated_value' => ['required', 'numeric', 'min:0'], 'next_action_on' => ['nullable', 'date'], 'owner_user_id' => ['nullable', 'exists:users,id'], 'notes' => ['nullable', 'string', 'max:2000'], 'operating_branch_id' => ['nullable', 'integer']]);
        $owner = isset($data['owner_user_id']) ? User::query()->findOrFail($data['owner_user_id']) : null;
        if ($owner !== null) {
            $this->tenantAccess->assertUser($request->user(), $owner);
        }
        // The owner's branch when they have one; otherwise the actor's, the one
        // chosen in the form, or the company's only branch — never none.
        $data['operating_branch_id'] = $this->branches->resolve(
            $request->user(),
            isset($data['operating_branch_id']) ? (int) $data['operating_branch_id'] : null,
            $owner?->operating_branch_id === null ? null : (int) $owner->operating_branch_id,
            $owner?->operating_company_id === null ? null : (int) $owner->operating_company_id,
        );
        SalesLead::create($data + ['lead_no' => BusinessReference::make('LEAD')]);

        return back()->with('ok', 'أُضيفت الفرصة إلى مسار المبيعات.');
    }

    public function activity(Request $request, SalesLead $lead): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', 'in:call,email,meeting,note,proposal'], 'note' => ['required', 'string', 'max:3000'], 'occurred_at' => ['required', 'date'], 'stage' => ['nullable', 'in:new,qualified,proposal,negotiation,won,lost'], 'next_action_on' => ['nullable', 'date']]);
        $lead->activities()->create(['type' => $data['type'], 'note' => $data['note'], 'occurred_at' => $data['occurred_at'], 'user_id' => $request->user()->id]);
        $lead->forceFill(array_filter(['stage' => $data['stage'] ?? null, 'next_action_on' => $data['next_action_on'] ?? null], fn ($value) => $value !== null))->save();

        return back()->with('ok', 'سُجل نشاط المتابعة.');
    }

    public function expense(Request $request): RedirectResponse
    {
        $data = $request->validate(['vehicle_id' => ['required', 'exists:vehicles,id'], 'category' => ['required', 'in:fuel,maintenance,fine,insurance,other'], 'amount' => ['required', 'numeric', 'gt:0'], 'incurred_on' => ['required', 'date'], 'odometer_km' => ['nullable', 'numeric', 'min:0'], 'reference' => ['nullable', 'string', 'max:190'], 'note' => ['nullable', 'string', 'max:1000']]);
        // `exists` ignores the tenant scope; the scoped load is the authorization.
        Vehicle::query()->findOrFail($data['vehicle_id']);
        VehicleExpense::create($data + ['recorded_by' => $request->user()->id]);

        return back()->with('ok', 'سُجل مصروف السيارة.');
    }

    public function commissionRule(Request $request): RedirectResponse
    {
        $actor = $request->user();
        // A policy belongs to one company and pays only its people. A company-wide
        // owner files policies for their own company; only a platform admin may
        // file one for another company.
        abort_unless($actor->isPlatformAdmin() || ($actor->isOwner() && $actor->operating_company_id !== null && $actor->operating_branch_id === null), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:190'], 'applies_to_role' => ['required', 'in:owner_supervisor,admin,technician'], 'basis' => ['required', 'in:contract_value,collected_payment,completed_visit'], 'rate' => ['required', 'numeric', 'min:0', 'max:100'], 'fixed_amount' => ['required', 'numeric', 'min:0'], 'operating_company_id' => ['nullable', 'integer', 'exists:operating_companies,id']]);
        $companyId = $actor->isPlatformAdmin() ? ($data['operating_company_id'] ?? $actor->operating_company_id) : $actor->operating_company_id;
        if ($companyId === null) {
            return back()->with('err', 'اختر الشركة التي تتبعها سياسة العمولة.');
        }
        unset($data['operating_company_id']);
        $rule = CommissionRule::create($data + ['operating_company_id' => (int) $companyId, 'is_active' => true]);
        $this->audit->record('commission_rule.created', $rule, null, $rule->only(['operating_company_id', 'name', 'applies_to_role', 'basis', 'rate', 'fixed_amount']), $actor->id);

        return back()->with('ok', 'أُضيفت سياسة العمولة.');
    }

    public function commissionEntry(Request $request): RedirectResponse
    {
        $data = $request->validate(['commission_rule_id' => ['required', 'exists:commission_rules,id'], 'user_id' => ['required', 'exists:users,id'], 'contract_id' => ['nullable', 'exists:contracts,id'], 'visit_id' => ['nullable', 'exists:visits,id'], 'payment_id' => ['nullable', 'exists:payments,id'], 'contract_no' => ['nullable', 'string', 'max:64'], 'receipt_no' => ['nullable', 'string', 'max:48']]);
        $targetUser = User::query()->findOrFail($data['user_id']);
        $this->tenantAccess->assertUser($request->user(), $targetUser);
        // References typed by the user resolve through the tenant scope.
        $contract = filled($data['contract_no'] ?? null) ? Contract::query()->where('contract_no', trim($data['contract_no']))->firstOrFail()
            : (isset($data['contract_id']) ? Contract::find($data['contract_id']) : null);
        $payment = filled($data['receipt_no'] ?? null) ? Payment::query()->where('receipt_no', trim($data['receipt_no']))->firstOrFail()
            : (isset($data['payment_id']) ? Payment::find($data['payment_id']) : null);
        // Company-owned policies and visits resolve through the tenant scope too;
        // resolved outside the try so a foreign id is a 404, not a flash message.
        $rule = CommissionRule::query()->findOrFail($data['commission_rule_id']);
        $visit = isset($data['visit_id']) ? Visit::query()->findOrFail($data['visit_id']) : null;
        try {
            $this->commissions->create($rule, $targetUser, $contract, $visit, $payment);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', 'حُسب استحقاق العمولة حسب السياسة.');
    }

    public function approvalRequest(Request $request): RedirectResponse
    {
        $data = $request->validate(['action_type' => ['required', 'in:discount,settlement'], 'subject_type' => ['required', 'in:quotation,installment'], 'subject_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:2000']]);
        $subject = ($data['subject_type'] === 'quotation' ? Quotation::query() : ContractInstallment::query())->findOrFail($data['subject_id']);
        if ($subject instanceof Quotation && $data['action_type'] === 'discount') {
            try {
                $this->approvals->assertQuotationDiscountable($subject);
            } catch (RuntimeException $e) {
                return back()->with('err', $e->getMessage());
            }
        }
        FinancialApproval::create($data + ['public_reference' => (string) Str::uuid(), 'status' => 'pending_first', 'requested_by' => $request->user()->id]);

        return back()->with('ok', 'أُرسل الطلب للاعتماد الأول ثم اعتماد مستخدم ثانٍ مستقل.');
    }

    public function approve(Request $request, FinancialApproval $approval): RedirectResponse
    {
        $requester = User::query()->find($approval->requested_by);
        abort_unless($requester !== null, 404);
        $this->tenantAccess->assertUser($request->user(), $requester);
        try {
            $this->approvals->approve($approval, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', 'سُجل الاعتماد؛ يُطبق الأثر المالي فقط بعد الاعتماد الثاني.');
    }

    public function permissions(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isOwner(), 403);
        $this->tenantAccess->assertUser($request->user(), $user);
        $data = $request->validate(['permissions' => ['nullable', 'array'], 'permissions.*' => ['in:operations,clients,commercial,finance,inventory,intelligence,team,hr,fleet,performance,sales,admin'], 'full_access' => ['nullable', 'boolean']]);
        $before = $user->permissions;
        // Full access is an explicit choice. An empty selection means no panel
        // area at all — it used to fall back to ['*'] and silently grant everything.
        $permissions = $request->boolean('full_access') ? ['*'] : array_values(array_unique($data['permissions'] ?? []));
        $user->forceFill(['permissions' => $permissions])->save();
        $this->audit->record('user.permissions_changed', $user, ['permissions' => $before], ['permissions' => $user->permissions], $request->user()->id);

        return back()->with('ok', 'حُفظت صلاحيات المستخدم.');
    }
}
