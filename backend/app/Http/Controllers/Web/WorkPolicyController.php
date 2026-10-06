<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OperatingCompany;
use App\Models\WorkPolicy;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Owner-only register of the working-hours policy versions supplied by a
 * qualified specialist. Versions are immutable: a change is a new version.
 */
class WorkPolicyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $companyId = $this->ownerCompany($request);

        return view('panel.work-policies', [
            'active' => WorkPolicy::query()->effectiveOn($companyId, today())->first(),
            'versions' => WorkPolicy::query()->with('creator')->where('operating_company_id', $companyId)
                ->orderByDesc('version')->paginate(20, ['*'], 'versions_page')->withQueryString(),
            'ruleLabels' => WorkPolicy::RULES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->ownerCompany($request);
        $data = $request->validate([
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
            'source' => ['required', 'string', 'max:2000'],
            'approved_by' => ['required', 'string', 'max:500'],
            'approved_at' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:4000'],
            // Physical bounds only (a day / a week in minutes) — the policy
            // values themselves come from the specialist, not from Darak.
            'rules.max_shift_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'rules.max_daily_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'rules.max_weekly_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'rules.min_rest_between_shifts_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
        ]);
        $rules = array_map('intval', array_filter(
            array_intersect_key($data['rules'] ?? [], WorkPolicy::RULES),
            fn ($value) => $value !== null && $value !== '',
        ));
        if ($rules === []) {
            throw ValidationException::withMessages(['rules' => 'أدخل قاعدة واحدة على الأقل كما وردت من المختص.']);
        }

        $policy = DB::transaction(function () use ($request, $companyId, $data, $rules): WorkPolicy {
            OperatingCompany::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $latest = WorkPolicy::query()->where('operating_company_id', $companyId)->orderByDesc('version')->first();
            if ($latest !== null && $latest->effective_from->gt($data['effective_from'])) {
                throw ValidationException::withMessages([
                    'effective_from' => 'تاريخ السريان يجب ألا يسبق سريان الإصدار السابق ('.$latest->effective_from->format('Y-m-d').').',
                ]);
            }

            $policy = WorkPolicy::create([
                'operating_company_id' => $companyId,
                'version' => ($latest?->version ?? 0) + 1,
                'effective_from' => $data['effective_from'],
                'source' => $data['source'],
                'approved_by' => $data['approved_by'],
                'approved_at' => $data['approved_at'],
                'rules' => $rules,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $this->audit->record('work_policy.version_created', $policy, null, [
                'version' => $policy->version, 'effective_from' => $data['effective_from'],
                'source' => $data['source'], 'approved_by' => $data['approved_by'],
                'approved_at' => $data['approved_at'], 'rules' => $rules,
            ], $request->user()->id);

            return $policy;
        });

        return back()->with('ok', "أُضيف إصدار سياسة العمل رقم {$policy->version} ويسري من {$policy->effective_from->format('Y-m-d')}.");
    }

    private function ownerCompany(Request $request): int
    {
        $actor = $request->user();
        abort_unless($actor->isOwner() && $actor->operating_company_id !== null, 403);

        return (int) $actor->operating_company_id;
    }
}
