<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ContractInstallment;
use App\Models\OperatingBranch;
use App\Models\Payment;
use App\Models\PerformanceMetricSetting;
use App\Models\Quotation;
use App\Models\SalesActivity;
use App\Models\SalesLead;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitFeedback;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ranks technicians, supervisors, branches and marketers.
 *
 * Everything is computed from GROUP BY aggregates (and, for visit costs, in
 * chunks) — never by loading every visit of two periods into memory — and every
 * population is confined to the actor's company (and branch, when the actor is
 * branch-bound). `User` and `OperatingBranch` have no global tenant scope, so
 * that constraint is applied explicitly here.
 */
class PerformanceScoreService
{
    private const COST_CHUNK = 500;

    /** @var array<string, Collection<int, User>> */
    private array $supervisorsByScope = [];

    /** @var array<string, Collection<int, OperatingBranch>> */
    private array $branchesByScope = [];

    /** @var array<string, Collection<int, User>> */
    private array $marketersByScope = [];

    public const CATEGORY_LABELS = [
        'technicians' => 'الفنيون',
        'supervisors' => 'المشرفون',
        'branches' => 'الفروع',
        'marketers' => 'المسوقون',
    ];

    public function __construct(private readonly ProfitabilityService $profitability) {}

    /** @return array<string, mixed>|null */
    public function marketerScore(User $actor, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $settings = PerformanceMetricSetting::query()->where('category', 'marketers')->where('is_active', true)->get();

        return collect($this->marketers($actor, $from, $to, $settings))->firstWhere('id', $actor->id);
    }

    /** @return array<int, array<string, mixed>> */
    public function marketerScores(User $actor, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $settings = PerformanceMetricSetting::query()->where('category', 'marketers')->where('is_active', true)->get();

        return $this->marketers($actor, $from, $to, $settings);
    }

    /** @return array<string, mixed> */
    public function dashboard(User $actor, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $from->diffInDays($to) + 1;
        $previousTo = $from->subSecond();
        $previousFrom = $previousTo->subDays($days)->addSecond();
        $settings = PerformanceMetricSetting::query()->where('is_active', true)->get()->groupBy('category');

        $current = $this->calculateAll($actor, $from, $to, $settings);
        $previous = $this->calculateAll($actor, $previousFrom, $previousTo, $settings);

        foreach ($current as $category => &$rows) {
            $previousById = collect($previous[$category] ?? [])->keyBy('id');
            foreach ($rows as &$row) {
                $old = $previousById->get($row['id']);
                $row['score_delta'] = round($row['score'] - (float) ($old['score'] ?? $row['score']), 1);
                $row['rank_delta'] = $old ? ((int) $old['rank'] - (int) $row['rank']) : 0;
            }
            unset($row);
        }
        unset($rows);

        return [
            'from' => $from, 'to' => $to,
            'previous_from' => $previousFrom, 'previous_to' => $previousTo,
            'categories' => $current,
            'summaries' => collect($current)->map(fn (array $rows, string $key): array => [
                'key' => $key, 'label' => self::CATEGORY_LABELS[$key],
                'count' => count($rows),
                'average' => count($rows) ? round(collect($rows)->avg('score'), 1) : 0,
                'leader' => $rows[0]['name'] ?? 'لا توجد بيانات',
                'leader_score' => $rows[0]['score'] ?? 0,
            ])->values()->all(),
        ];
    }

    /** @param Collection<string, Collection<int, PerformanceMetricSetting>> $settings
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function calculateAll(User $actor, CarbonImmutable $from, CarbonImmutable $to, Collection $settings): array
    {
        $stats = $this->visitStats($from, $to);

        return [
            'technicians' => $this->technicians($actor, $from, $to, $settings->get('technicians', collect()), $stats),
            'supervisors' => $this->supervisors($actor, $from, $to, $settings->get('supervisors', collect()), $stats),
            'branches' => $this->branches($actor, $from, $to, $settings->get('branches', collect()), $stats),
            'marketers' => $this->marketers($actor, $from, $to, $settings->get('marketers', collect())),
        ];
    }

    /**
     * One aggregate row per (technician, client branch) for the visible visits
     * scheduled in the period, plus the summed cost of the completed ones.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function visitStats(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $asOf = $to->min(CarbonImmutable::now());
        $completed = Visit::STATE_COMPLETED;
        $onTime = 'COALESCE(visits.closed_at, visits.ended_at) <= perf_wo.sla_due_at';

        $rows = $this->periodVisits($from, $to)
            ->leftJoin('work_orders as perf_wo', 'perf_wo.id', '=', 'visits.work_order_id')
            ->leftJoin('visit_feedback as perf_fb', 'perf_fb.visit_id', '=', 'visits.id')
            ->select('visits.assigned_user_id as user_id', 'perf_clients.operating_branch_id as branch_id')
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw('SUM(CASE WHEN visits.state = ? THEN 1 ELSE 0 END) AS completed', [$completed])
            ->selectRaw('SUM(CASE WHEN visits.state = ? AND perf_wo.sla_due_at IS NOT NULL THEN 1 ELSE 0 END) AS sla_total', [$completed])
            ->selectRaw("SUM(CASE WHEN visits.state = ? AND perf_wo.sla_due_at IS NOT NULL AND {$onTime} THEN 1 ELSE 0 END) AS sla_met", [$completed])
            ->selectRaw('SUM(CASE WHEN visits.state = ? AND (visits.is_rework IS NULL OR visits.is_rework = ?) THEN 1 ELSE 0 END) AS first_time', [$completed, false])
            ->selectRaw("SUM(CASE WHEN visits.state = ? AND visits.ended_at IS NOT NULL AND TRIM(COALESCE(perf_wo.resolution_summary, '')) <> '' THEN 1 ELSE 0 END) AS documented", [$completed])
            ->selectRaw('SUM(CASE WHEN visits.state = ? AND perf_fb.id IS NOT NULL THEN 1 ELSE 0 END) AS feedback_count', [$completed])
            ->selectRaw('SUM(CASE WHEN visits.state = ? AND perf_fb.id IS NOT NULL THEN perf_fb.rating ELSE 0 END) AS rating_sum', [$completed])
            ->selectRaw('SUM(CASE WHEN perf_wo.sla_due_at IS NOT NULL AND perf_wo.sla_due_at <= ? THEN 1 ELSE 0 END) AS due', [$asOf])
            ->selectRaw("SUM(CASE WHEN perf_wo.sla_due_at IS NOT NULL AND perf_wo.sla_due_at <= ? AND visits.state = ? AND {$onTime} THEN 1 ELSE 0 END) AS due_on_time", [$asOf, $completed])
            ->groupBy('visits.assigned_user_id', 'perf_clients.operating_branch_id')
            ->toBase()->get();

        // Costs keep the profitability rules in one place; completed visits are
        // costed a chunk at a time so memory stays flat however busy the period.
        $costs = [];
        $this->periodVisits($from, $to)->where('visits.state', $completed)
            ->select('visits.id', 'visits.assigned_user_id', 'perf_clients.operating_branch_id')
            ->toBase()
            ->chunkById(self::COST_CHUNK, function (Collection $chunk) use (&$costs): void {
                $byVisit = $this->profitability->forVisitIds($chunk->pluck('id')->all());
                foreach ($chunk as $visit) {
                    $key = $visit->assigned_user_id.'|'.$visit->operating_branch_id;
                    $costs[$key] = Money::add($costs[$key] ?? 0, $byVisit[$visit->id]['cost'] ?? 0);
                }
            }, 'visits.id', 'id');

        return $rows->map(function (object $row) use ($costs): array {
            $stats = [
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
            ];
            foreach (['visits', 'completed', 'sla_total', 'sla_met', 'first_time', 'documented', 'feedback_count', 'rating_sum', 'due', 'due_on_time'] as $key) {
                $stats[$key] = (int) $row->{$key};
            }
            $stats['cost'] = $costs[$row->user_id.'|'.$row->branch_id] ?? Money::zero();

            return $stats;
        });
    }

    private function periodVisits(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        // The Visit global scope keeps this inside the actor's tenancy.
        return Visit::query()
            ->leftJoin('sites as perf_sites', 'perf_sites.id', '=', 'visits.site_id')
            ->leftJoin('clients as perf_clients', 'perf_clients.id', '=', 'perf_sites.client_id')
            ->whereBetween('visits.scheduled_start', [$from, $to]);
    }

    /** @param Collection<int, array<string, mixed>> $rows
     * @return array<string, int|string>
     */
    private function sumStats(Collection $rows): array
    {
        $sum = ['visits' => 0, 'completed' => 0, 'sla_total' => 0, 'sla_met' => 0, 'first_time' => 0, 'documented' => 0,
            'feedback_count' => 0, 'rating_sum' => 0, 'due' => 0, 'due_on_time' => 0, 'cost' => Money::zero()];
        foreach ($rows as $row) {
            foreach ($sum as $key => $value) {
                $sum[$key] = $key === 'cost' ? Money::add($value, $row['cost']) : $value + $row[$key];
            }
        }

        return $sum;
    }

    /** @return array<int, array<string, mixed>> */
    private function technicians(User $actor, CarbonImmutable $from, CarbonImmutable $to, Collection $settings, Collection $stats): array
    {
        $users = $this->tenantUsers($actor)->where('role', User::ROLE_TECHNICIAN)
            ->where(fn (Builder $q) => $q->where('is_active', true)->orWhereHas('visits', fn (Builder $v) => $v->whereBetween('scheduled_start', [$from, $to])))
            ->with('operatingBranch')->orderBy('name')->get();
        $byUser = $stats->groupBy('user_id');

        return $this->rank($users->map(function (User $user) use ($settings, $byUser): array {
            $s = $this->sumStats($byUser->get($user->id, collect()));
            $costPerVisit = $s['completed'] ? Money::div($s['cost'], $s['completed']) : Money::zero();

            $values = [
                'completed_visits' => [$s['completed'], $s['completed'], number_format($s['completed']).' زيارة'],
                'sla_rate' => [$this->percent($s['sla_met'], $s['sla_total']), $s['sla_total'], null],
                'first_time_fix' => [$this->percent($s['first_time'], $s['completed']), $s['completed'], null],
                'customer_rating' => $this->rating($s),
                'documentation' => [$this->percent($s['documented'], $s['completed']), $s['completed'], null],
                'completion_rate' => [$this->percent($s['completed'], $s['visits']), $s['visits'], null],
                'cost_per_visit' => [Money::toFloat($costPerVisit), $s['completed'], $s['completed'] ? Money::format($costPerVisit, 0).' ر.س' : '—'],
            ];

            return $this->entity($user->id, $user->name, $user->operatingBranch?->name ?? 'الإدارة العامة', $values, $settings);
        })->all());
    }

    /** @return array<int, array<string, mixed>> */
    private function supervisors(User $actor, CarbonImmutable $from, CarbonImmutable $to, Collection $settings, Collection $stats): array
    {
        $users = $this->supervisorsByScope[$this->scopeKey($actor)] ??= $this->tenantUsers($actor)
            ->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])
            ->where('is_active', true)->with('operatingBranch')->orderBy('name')->get();

        // Complaint handling: two counts per reviewer, streamed — not every row at once.
        $handled = [];
        VisitFeedback::query()->whereIn('reviewed_by', $users->modelKeys())
            ->whereBetween('reviewed_at', [$from, $to])
            ->select(['id', 'reviewed_by', 'created_at', 'reviewed_at'])
            ->lazyById(self::COST_CHUNK)
            ->each(function (VisitFeedback $feedback) use (&$handled): void {
                $handled[$feedback->reviewed_by] ??= ['total' => 0, 'fast' => 0];
                $handled[$feedback->reviewed_by]['total']++;
                if ($feedback->created_at->diffInHours($feedback->reviewed_at) <= 24) {
                    $handled[$feedback->reviewed_by]['fast']++;
                }
            });
        $actionsByUser = AuditLog::query()->whereIn('user_id', $users->modelKeys())->whereBetween('created_at', [$from, $to])
            ->where(function (Builder $q): void {
                $q->where('action', 'like', 'visit.%')->orWhere('action', 'like', 'visit_feedback.%')
                    ->orWhere('action', 'like', 'report_dispute.%')->orWhere('action', 'like', 'client_service_request.%')
                    ->orWhere('action', 'like', 'emergency.%')->orWhere('action', 'like', 'additional_work.%');
            })->select('user_id')->selectRaw('COUNT(*) AS total')->groupBy('user_id')->pluck('total', 'user_id');

        return $this->rank($users->map(function (User $user) use ($settings, $stats, $handled, $actionsByUser): array {
            $s = $this->sumStats($user->operating_branch_id === null
                ? $stats
                : $stats->where('branch_id', (int) $user->operating_branch_id));
            $reviews = $handled[$user->id] ?? ['total' => 0, 'fast' => 0];
            $actions = (int) ($actionsByUser->get($user->id) ?? 0);

            $values = [
                'team_sla' => [$this->percent($s['sla_met'], $s['sla_total']), $s['sla_total'], null],
                'team_first_time_fix' => [$this->percent($s['first_time'], $s['completed']), $s['completed'], null],
                'team_rating' => $this->rating($s),
                'complaint_response' => [$this->percent($reviews['fast'], $reviews['total']), $reviews['total'], null],
                'operational_actions' => [$actions, $actions, $actions.' إجراء'],
                'overdue_control' => [$this->percent($s['due_on_time'], $s['due']), $s['due'], null],
            ];

            return $this->entity($user->id, $user->name, $user->operatingBranch?->name ?? 'جميع الفروع', $values, $settings);
        })->all());
    }

    /** @return array<int, array<string, mixed>> */
    private function branches(User $actor, CarbonImmutable $from, CarbonImmutable $to, Collection $settings, Collection $stats): array
    {
        $branches = $this->branchesByScope[$this->scopeKey($actor)] ??= OperatingBranch::query()
            ->where('is_active', true)
            ->when(! $actor->isPlatformAdmin(), fn (Builder $q) => $q->where('operating_company_id', $this->actorCompanyId($actor) ?? 0))
            ->when($actor->operating_branch_id, fn (Builder $q, int $id) => $q->whereKey($id))
            ->with('company')->orderBy('name')->get();
        $branchIds = $branches->modelKeys();
        $installmentsByBranch = ContractInstallment::query()
            ->join('contracts', 'contracts.id', '=', 'contract_installments.contract_id')
            ->join('clients', 'clients.id', '=', 'contracts.client_id')
            ->whereIn('clients.operating_branch_id', $branchIds)
            ->whereBetween('contract_installments.due_on', [$from->toDateString(), $to->toDateString()])
            ->select('clients.operating_branch_id')
            ->selectRaw('SUM(contract_installments.total_amount) AS due_total')
            ->selectRaw('SUM(contract_installments.paid_amount) AS paid_total')
            ->selectRaw('COUNT(*) AS installment_count')
            ->groupBy('clients.operating_branch_id')->get()->keyBy('operating_branch_id');
        $revenueByBranch = Payment::query()->join('clients as payment_clients', 'payment_clients.id', '=', 'payments.client_id')
            ->whereIn('payment_clients.operating_branch_id', $branchIds)
            ->whereBetween('payments.paid_on', [$from->toDateString(), $to->toDateString()])
            ->whereNull('payments.refunded_at')
            ->select('payment_clients.operating_branch_id')->selectRaw('SUM(payments.amount) AS total')
            ->groupBy('payment_clients.operating_branch_id')->pluck('total', 'payment_clients.operating_branch_id');

        return $this->rank($branches->map(function (OperatingBranch $branch) use ($settings, $stats, $installmentsByBranch, $revenueByBranch): array {
            $s = $this->sumStats($stats->where('branch_id', $branch->id));
            $installments = $installmentsByBranch->get($branch->id);
            $revenue = Money::of($revenueByBranch->get($branch->id));
            $margin = Money::ratioPercent(Money::sub($revenue, $s['cost']), $revenue, 4) ?? 0.0;
            $collectionRate = Money::ratioPercent($installments?->paid_total, $installments?->due_total, 4);

            $values = [
                'sla_rate' => [$this->percent($s['sla_met'], $s['sla_total']), $s['sla_total'], null],
                'first_time_fix' => [$this->percent($s['first_time'], $s['completed']), $s['completed'], null],
                'customer_rating' => $this->rating($s),
                'collection_rate' => [$collectionRate === null ? 0 : min(100, $collectionRate), (int) ($installments?->installment_count ?? 0), null],
                'profit_margin' => [$margin, $s['completed'], number_format($margin, 1).'%'],
                'completion_rate' => [$this->percent($s['completed'], $s['visits']), $s['visits'], null],
            ];

            return $this->entity($branch->id, $branch->name, $branch->company?->name ?? '—', $values, $settings);
        })->all());
    }

    /** @return array<int, array<string, mixed>> */
    private function marketers(User $actor, CarbonImmutable $from, CarbonImmutable $to, Collection $settings): array
    {
        $users = $this->marketersByScope[$this->scopeKey($actor)] ??= $this->tenantUsers($actor)
            ->where(fn (Builder $q) => $q
                ->whereIn('id', SalesLead::query()->whereNotNull('owner_user_id')->select('owner_user_id'))
                ->orWhereIn('id', Quotation::query()->whereNotNull('created_by')->select('created_by')))
            ->with('operatingBranch')->get();
        $userIds = $users->modelKeys();
        $leadsByUser = SalesLead::query()->whereIn('owner_user_id', $userIds)->whereBetween('created_at', [$from, $to])
            ->select('owner_user_id')->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN stage = 'won' THEN 1 ELSE 0 END) AS won")
            ->groupBy('owner_user_id')->get()->keyBy('owner_user_id');
        $pipelineByUser = SalesLead::query()->whereIn('owner_user_id', $userIds)
            ->whereIn('stage', ['new', 'qualified', 'proposal', 'negotiation'])
            ->select('owner_user_id')->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN next_action_on IS NOT NULL THEN 1 ELSE 0 END) AS planned')
            ->groupBy('owner_user_id')->get()->keyBy('owner_user_id');
        $quotesByUser = Quotation::query()->whereIn('created_by', $userIds)->whereBetween('created_at', [$from, $to])
            ->select('created_by')
            ->selectRaw("SUM(CASE WHEN status IN ('accepted', 'converted', 'rejected') THEN 1 ELSE 0 END) AS decided")
            ->selectRaw("SUM(CASE WHEN status IN ('accepted', 'converted') THEN 1 ELSE 0 END) AS accepted")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('accepted', 'converted') THEN price_amount ELSE 0 END), 0) AS accepted_value")
            ->groupBy('created_by')->get()->keyBy('created_by');
        $activitiesByUser = SalesActivity::query()->whereIn('user_id', $userIds)
            ->whereBetween('occurred_at', [$from, $to])->select('user_id')
            ->selectRaw('COUNT(*) AS total')->groupBy('user_id')->pluck('total', 'user_id');
        $collectionsByUser = Payment::query()
            ->join('contract_installments', 'contract_installments.id', '=', 'payments.contract_installment_id')
            ->join('contracts', 'contracts.id', '=', 'contract_installments.contract_id')
            ->join('quotations', 'quotations.id', '=', 'contracts.source_quotation_id')
            ->whereIn('quotations.created_by', $userIds)
            ->whereBetween('payments.paid_on', [$from->toDateString(), $to->toDateString()])
            ->whereNull('payments.refunded_at')
            ->select('quotations.created_by')->selectRaw('SUM(payments.amount) AS total')
            ->groupBy('quotations.created_by')->pluck('total', 'quotations.created_by');

        return $this->rank($users->map(function (User $user) use ($settings, $leadsByUser, $pipelineByUser, $quotesByUser, $activitiesByUser, $collectionsByUser): array {
            $leads = $leadsByUser->get($user->id);
            $quotes = $quotesByUser->get($user->id);
            $pipeline = $pipelineByUser->get($user->id);
            $activities = (int) ($activitiesByUser->get($user->id) ?? 0);
            $accepted = (int) ($quotes?->accepted ?? 0);
            $wonValue = Money::of($quotes?->accepted_value);
            $collections = Money::of($collectionsByUser->get($user->id));

            $values = [
                'lead_conversion' => [$this->percent((int) ($leads?->won ?? 0), (int) ($leads?->total ?? 0)), (int) ($leads?->total ?? 0), null],
                'quote_acceptance' => [$this->percent($accepted, (int) ($quotes?->decided ?? 0)), (int) ($quotes?->decided ?? 0), null],
                'won_value' => [Money::toFloat($wonValue), $accepted, Money::format($wonValue, 0).' ر.س'],
                'collections' => [Money::toFloat($collections), Money::isPositive($collections) ? 1 : 0, Money::format($collections, 0).' ر.س'],
                'follow_up' => [$activities, $activities, $activities.' متابعة'],
                'pipeline_hygiene' => [$this->percent((int) ($pipeline?->planned ?? 0), (int) ($pipeline?->total ?? 0)), (int) ($pipeline?->total ?? 0), null],
            ];

            return $this->entity($user->id, $user->name, $user->operatingBranch?->name ?? 'الإدارة العامة', $values, $settings);
        })->all());
    }

    /**
     * Users of the actor's company — and of the actor's branch when branch-bound.
     * Legacy rows that only carry a branch are matched through that branch.
     */
    private function tenantUsers(User $actor): Builder
    {
        $query = User::query();
        if (! $actor->isPlatformAdmin()) {
            $companyId = $this->actorCompanyId($actor);
            if ($companyId === null) {
                return $query->whereRaw('1 = 0');
            }
            $query->where(fn (Builder $q) => $q->where('operating_company_id', $companyId)
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('operating_company_id')
                    ->whereIn('operating_branch_id', OperatingBranch::query()->where('operating_company_id', $companyId)->select('id'))));
        }

        return $query->when($actor->operating_branch_id, fn (Builder $q, int $id) => $q->where('operating_branch_id', $id));
    }

    private function actorCompanyId(User $actor): ?int
    {
        if ($actor->operating_company_id !== null) {
            return (int) $actor->operating_company_id;
        }
        $branchCompany = $actor->operating_branch_id
            ? OperatingBranch::query()->whereKey($actor->operating_branch_id)->value('operating_company_id')
            : null;

        return $branchCompany === null ? null : (int) $branchCompany;
    }

    private function scopeKey(User $actor): string
    {
        return ($actor->isPlatformAdmin() ? 'platform' : (string) $this->actorCompanyId($actor)).'|'.($actor->operating_branch_id ?? 'all');
    }

    /** @param array<string, int|string> $s
     * @return array{0: float|int, 1: int, 2: string}
     */
    private function rating(array $s): array
    {
        if ($s['feedback_count'] === 0) {
            return [0, 0, '—'];
        }
        $average = $s['rating_sum'] / $s['feedback_count'];

        return [$average * 20, $s['feedback_count'], number_format($average, 1).'/5'];
    }

    /** @param array<string, array{0: float|int, 1: int, 2: ?string}> $values
     * @return array<string, mixed>
     */
    private function entity(int $id, string $name, string $context, array $values, Collection $settings): array
    {
        $metrics = $settings->map(function (PerformanceMetricSetting $setting) use ($values): array {
            [$value, $samples, $display] = $values[$setting->metric_key] ?? [0, 0, null];
            $raw = $this->metricScore((float) $value, (float) $setting->target, $setting->direction);
            $confidence = min(1, $samples / max(1, $setting->minimum_sample));
            // Sparse samples should neither crown nor punish someone. Their score
            // stays near the neutral midpoint until the configured sample is met.
            $adjusted = 50 + (($raw - 50) * $confidence);

            return [
                'key' => $setting->metric_key, 'label' => $setting->label_ar,
                'value' => round((float) $value, 1),
                'display' => $display ?? number_format((float) $value, 1).'%',
                'score' => round(max(0, min(100, $adjusted)), 1),
                'weight' => (float) $setting->weight, 'target' => (float) $setting->target,
                'samples' => (int) $samples, 'minimum_sample' => $setting->minimum_sample,
                'sufficient' => $samples >= $setting->minimum_sample,
            ];
        })->values();
        $totalWeight = max(1, (float) $metrics->sum('weight'));
        $score = round((float) $metrics->sum(fn (array $m) => $m['score'] * $m['weight']) / $totalWeight, 1);
        $sufficient = $metrics->filter(fn (array $m) => $m['sufficient'])->count() >= (int) ceil($metrics->count() / 2);

        return [
            'id' => $id, 'name' => $name, 'context' => $context, 'score' => $score,
            'grade' => $this->grade($score), 'sufficient' => $sufficient,
            'metrics' => $metrics->all(),
            'strengths' => $metrics->sortByDesc('score')->take(2)->pluck('label')->all(),
            'improvements' => $metrics->sortBy('score')->take(2)->pluck('label')->all(),
        ];
    }

    /** @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function rank(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => ((int) $b['sufficient'] <=> (int) $a['sufficient'])
            ?: ($b['score'] <=> $a['score'])
            ?: strcmp($a['name'], $b['name'])
        );
        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
        }

        return $rows;
    }

    private function percent(int|float $part, int|float $whole): float
    {
        return $whole > 0 ? min(100, max(0, ($part / $whole) * 100)) : 0;
    }

    private function metricScore(float $value, float $target, string $direction): float
    {
        if ($direction === 'lower') {
            if ($target <= 0) {
                return $value <= 0 ? 100 : 0;
            }

            return $value <= $target ? 100 : max(0, ($target / max($value, 0.01)) * 100);
        }

        return $target > 0 ? min(100, max(0, ($value / $target) * 100)) : ($value > 0 ? 100 : 0);
    }

    private function grade(float $score): string
    {
        return match (true) {
            $score >= 90 => 'ممتاز', $score >= 80 => 'جيد جدًا',
            $score >= 70 => 'جيد', $score >= 60 => 'مقبول',
            default => 'يحتاج تحسين',
        };
    }
}
