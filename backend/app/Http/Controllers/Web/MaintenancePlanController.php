<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MaintenancePlan;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MaintenancePlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MaintenancePlanController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('panel.maintenance', [
            'plans' => MaintenancePlan::with(['client', 'site', 'asset', 'contract', 'preferredTechnician'])->orderBy('next_due_on')->get(),
            'clients' => Client::with(['sites.assets', 'contracts'])->where('is_active', true)->orderBy('name')->get(),
            'technicians' => User::where('role', User::ROLE_TECHNICIAN)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'site_id' => ['required', Rule::exists('sites', 'id')->where('client_id', $request->integer('client_id'))],
            'contract_id' => ['nullable', Rule::exists('contracts', 'id')->where('client_id', $request->integer('client_id'))->where('status', 'active')],
            'asset_id' => ['nullable', Rule::exists('assets', 'id')->where('site_id', $request->integer('site_id'))],
            'title' => ['required', 'string', 'max:190'],
            'frequency_days' => ['required', 'integer', 'min:1', 'max:730'],
            'duration_minutes' => ['required', 'integer', 'min:30', 'max:720'],
            'preferred_start' => ['nullable', 'date_format:H:i'],
            'next_due_on' => ['required', 'date'],
            'preferred_user_id' => ['nullable', Rule::exists('users', 'id')->where('role', User::ROLE_TECHNICIAN)->where('is_active', true)],
        ]);

        $plan = MaintenancePlan::create($data + ['is_active' => true, 'created_by' => $request->user()->id]);
        $this->audit->record('maintenance_plan.created', $plan, null, $plan->only(['client_id', 'site_id', 'asset_id', 'frequency_days', 'next_due_on']), $request->user()->id);

        return back()->with('ok', 'تم إنشاء خطة الصيانة الوقائية.');
    }

    public function toggle(Request $request, MaintenancePlan $plan): RedirectResponse
    {
        $before = $plan->getAttributes();
        $plan->forceFill(['is_active' => ! $plan->is_active])->save();
        $this->audit->recordChange('maintenance_plan.toggled', $plan, $before, $request->user()->id);

        return back()->with('ok', $plan->is_active ? 'تم تفعيل الخطة.' : 'تم إيقاف الخطة.');
    }

    public function generate(MaintenancePlanService $service): RedirectResponse
    {
        $count = $service->generateDuePlans();

        return back()->with('ok', "تم توليد {$count} زيارة صيانة مستحقة.");
    }
}
