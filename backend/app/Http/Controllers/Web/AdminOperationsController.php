<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\FinancialApproval;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\SalesLead;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\Visit;
use App\Services\AuditLogger;
use App\Services\CommissionService;
use App\Services\FinancialApprovalService;
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
    public function __construct(private readonly CommissionService $commissions, private readonly FinancialApprovalService $approvals, private readonly AuditLogger $audit, private readonly TenantAccess $tenantAccess) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        abort_if(! $actor->isPlatformAdmin() && $actor->operating_company_id === null, 403);
        $companyId = $actor->operating_company_id;

        return view('panel.admin-operations', [
            'companies' => OperatingCompany::with('branches')->when(! $actor->isPlatformAdmin(), fn ($query) => $query->whereKey($companyId))->get(),
            'branches' => OperatingBranch::with('company')->when(! $actor->isPlatformAdmin(), fn ($query) => $query->where('operating_company_id', $companyId))->get(),
            'leads' => SalesLead::with(['owner', 'activities'])->latest()->limit(50)->get(),
            'vehicles' => Vehicle::with('assignedUser')->get(), 'expenses' => VehicleExpense::with('vehicle')->latest()->limit(50)->get(),
            'rules' => CommissionRule::latest()->get(),
            'entries' => CommissionEntry::with(['rule', 'user'])
                ->when(! $actor->isPlatformAdmin(), fn ($query) => $query->whereHas('user', fn ($user) => $user->where('operating_company_id', $companyId)))
                ->latest()->limit(50)->get(),
            'approvals' => FinancialApproval::with(['requester', 'firstApprover', 'secondApprover'])
                ->when(! $actor->isPlatformAdmin(), fn ($query) => $query->whereHas('requester', fn ($user) => $user->where('operating_company_id', $companyId)))
                ->latest()->limit(50)->get(),
            'users' => User::query()->when(! $actor->isPlatformAdmin(), fn ($query) => $query->where('operating_company_id', $companyId))->orderBy('name')->get(),
            'clients' => Client::orderBy('name')->get(),
            'contracts' => Contract::latest()->limit(100)->get(), 'visits' => Visit::latest()->limit(100)->get(),
            'payments' => Payment::latest()->limit(100)->get(), 'quotations' => Quotation::latest()->limit(100)->get(),
            'installments' => ContractInstallment::latest()->limit(100)->get(),
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
        $model = match ($data['entity_type']) {
            'user' => User::query()
                ->when(! $actor->isPlatformAdmin(), fn ($query) => $query->where('operating_company_id', $actor->operating_company_id))
                ->findOrFail($data['entity_id']),
            'client' => Client::findOrFail($data['entity_id']),
            'vehicle' => Vehicle::findOrFail($data['entity_id']),
        };
        $tenant = ['operating_branch_id' => $branch?->id];
        if ($model instanceof User || $model instanceof Client) {
            $tenant['operating_company_id'] = $branch?->operating_company_id
                ?? $model->operating_company_id
                ?? $actor->operating_company_id;
        }
        $model->forceFill($tenant)->save();

        return back()->with('ok', 'حُدث الفرع المرتبط بالسجل.');
    }

    public function lead(Request $request): RedirectResponse
    {
        $data = $request->validate(['company_name' => ['required', 'string', 'max:190'], 'contact_name' => ['nullable', 'string', 'max:190'], 'phone' => ['nullable', 'string', 'max:32'], 'email' => ['nullable', 'email'], 'stage' => ['required', 'in:new,qualified,proposal,negotiation,won,lost'], 'estimated_value' => ['required', 'numeric', 'min:0'], 'next_action_on' => ['nullable', 'date'], 'owner_user_id' => ['nullable', 'exists:users,id'], 'notes' => ['nullable', 'string', 'max:2000']]);
        if (isset($data['owner_user_id'])) {
            $this->tenantAccess->assertUser($request->user(), User::query()->findOrFail($data['owner_user_id']));
        }
        $leadBranchId = isset($data['owner_user_id'])
            ? User::query()->whereKey($data['owner_user_id'])->value('operating_branch_id')
            : $request->user()->operating_branch_id;
        SalesLead::create($data + ['operating_branch_id' => $leadBranchId, 'lead_no' => BusinessReference::make('LEAD')]);

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
        VehicleExpense::create($request->validate(['vehicle_id' => ['required', 'exists:vehicles,id'], 'category' => ['required', 'in:fuel,maintenance,fine,insurance,other'], 'amount' => ['required', 'numeric', 'gt:0'], 'incurred_on' => ['required', 'date'], 'odometer_km' => ['nullable', 'numeric', 'min:0'], 'reference' => ['nullable', 'string', 'max:190'], 'note' => ['nullable', 'string', 'max:1000']]) + ['recorded_by' => $request->user()->id]);

        return back()->with('ok', 'سُجل مصروف السيارة.');
    }

    public function commissionRule(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);
        CommissionRule::create($request->validate(['name' => ['required', 'string', 'max:190'], 'applies_to_role' => ['required', 'in:owner_supervisor,admin,technician'], 'basis' => ['required', 'in:contract_value,collected_payment,completed_visit'], 'rate' => ['required', 'numeric', 'min:0', 'max:100'], 'fixed_amount' => ['required', 'numeric', 'min:0']]) + ['is_active' => true]);

        return back()->with('ok', 'أُضيفت سياسة العمولة.');
    }

    public function commissionEntry(Request $request): RedirectResponse
    {
        $data = $request->validate(['commission_rule_id' => ['required', 'exists:commission_rules,id'], 'user_id' => ['required', 'exists:users,id'], 'contract_id' => ['nullable', 'exists:contracts,id'], 'visit_id' => ['nullable', 'exists:visits,id'], 'payment_id' => ['nullable', 'exists:payments,id']]);
        $targetUser = User::query()->findOrFail($data['user_id']);
        $this->tenantAccess->assertUser($request->user(), $targetUser);
        try {
            $this->commissions->create(CommissionRule::findOrFail($data['commission_rule_id']), $targetUser, isset($data['contract_id']) ? Contract::find($data['contract_id']) : null, isset($data['visit_id']) ? Visit::find($data['visit_id']) : null, isset($data['payment_id']) ? Payment::find($data['payment_id']) : null);
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', 'حُسب استحقاق العمولة حسب السياسة.');
    }

    public function approvalRequest(Request $request): RedirectResponse
    {
        $data = $request->validate(['action_type' => ['required', 'in:discount,settlement'], 'subject_type' => ['required', 'in:quotation,installment'], 'subject_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:2000']]);
        ($data['subject_type'] === 'quotation' ? Quotation::query() : ContractInstallment::query())->findOrFail($data['subject_id']);
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
        $data = $request->validate(['permissions' => ['nullable', 'array'], 'permissions.*' => ['in:operations,clients,commercial,finance,inventory,intelligence,team,hr,fleet,performance,sales,admin']]);
        $user->forceFill(['permissions' => $data['permissions'] ?? ['*']])->save();
        $this->audit->record('user.permissions_changed', $user, null, ['permissions' => $user->permissions], $request->user()->id);

        return back()->with('ok', 'حُفظت صلاحيات المستخدم.');
    }
}
