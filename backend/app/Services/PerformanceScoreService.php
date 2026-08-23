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
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PerformanceScoreService
{
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

        // Fetch the two adjacent comparison periods together. Eager-loaded
        // relations and profitability aggregates are then reused instead of
        // issuing the same query family twice.
        $allVisits = $this->visitQuery($previousFrom, $to)->get();
        $allVisitCosts = $this->profitability->forVisitIds($allVisits->modelKeys());
        $currentVisits = $allVisits->filter(fn (Visit $visit) => $visit->scheduled_start?->gte($from)
            && $visit->scheduled_start?->lte($to))->values();
        $previousVisits = $allVisits->filter(fn (Visit $visit) => $visit->scheduled_start?->gte($previousFrom)
            && $visit->scheduled_start?->lte($previousTo))->values();

        $current = $this->calculateAll($actor, $from, $to, $settings, $currentVisits, $allVisitCosts);
        $previous = $this->calculateAll($actor, $previousFrom, $previousTo, $settings, $previousVisits, $allVisitCosts);

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
    private function calculateAll(
        User $actor,
        CarbonImmutable $from,
        CarbonImmutable $to,
        Collection $settings,
        Collection $visits,
        array $visitCosts,
    ): array
    {
        return [
            'technicians' => $this->technicians($actor, $from, $to, $settings->get('technicians', collect()), $visits, $visitCosts),
            'supervisors' => $this->supervisors($actor, $from, $to, $settings->get('supervisors', collect()), $visits),
            'branches' => $this->branches($actor, $from, $to, $settings->get('branches', collect()), $visits, $visitCosts),
            'marketers' => $this->marketers($actor, $from, $to, $settings->get('marketers', collect())),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function technicians(User $actor, CarbonImmutable $from, CarbonImmutable $to, Collection $settings, Collection $periodVisits, array $visitCosts): array
    {
        $users = User::query()->where('role', User::ROLE_TECHNICIAN)
            ->when($actor->operating_branch_id, fn (Builder $q, int $id) => $q->where('operating_branch_id', $id))
            ->where(fn (Builder $q) => $q->where('is_active', true)->orWhereHas('visits', fn (Builder $v) => $v->whereBetween('scheduled_start', [$from, $to])))
            ->with('operatingBranch')->orderBy('name')->get();

        return $this->rank($users->map(function (User $user) use ($settings, $periodVisits, $visitCosts): array {
            $visits = $periodVisits->where('assigned_user_id', $user->id);
            $completed = $visits->where('state', Visit::STATE_COMPLETED);
            $feedback = $completed->pluck('feedback')->filter();
            $sla = $completed->filter(fn (Visit $visit) => $visit->workOrder?->sla_due_at)->values();
            $cost = (float) $completed->sum(fn (Visit $visit) => $visitCosts[$visit->id]['cost'] ?? 0);
            $documented = $completed->filter(fn (Visit $visit) => filled($visit->workOrder?->resolution_summary) && $visit->ended_at !== null);

            $values = [
                'completed_visits' => [$completed->count(), $completed->count(), number_format($completed->count()).' زيارة'],
                'sla_rate' => [$this->percent($sla->filter(fn (Visit $v) => ($v->closed_at ?? $v->ended_at)?->lte($v->workOrder->sla_due_at))->count(), $sla->count()), $sla->count(), null],
                'first_time_fix' => [$this->percent($completed->where('is_rework', false)->count(), $completed->count()), $completed->count(), null],
                'customer_rating' => [$feedback->count() ? (float) $feedback->avg('rating') * 20 : 0, $feedback->count(), $feedback->count() ? number_format((float) $feedback->avg('rating'), 1).'/5' : '—'],
                'documentation' => [$this->percent($documented->count(), $completed->count()), $completed->count(), null],
                'completion_rate' => [$this->percent($completed->count(), $visits->count()), $visits->count(), null],
                'cost_per_visit' => [$completed->count() ? $cost / $completed->count() : 0, $completed->count(), $completed->count() ? number_format($cost / $completed->count(), 0).' ر.س' : '—'],
            ];

            return $this->entity($user->id, $user->name, $user->operatingBranch?->name ?? 'الإدارة العامة', $values, $settings);
        })->all());
    }

    /** @return array<int, array<string, mixed>> */
    private function supervisors(User $actor, CarbonImmutable $from, CarbonImmutable $to, Collection $settings, Collection $periodVisits): array
    {
        $scope = (string) ($actor->operating_branch_id ?? 'all');
        $users = $this->supervisorsByScope[$scope] ??= User::query()
            ->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])
            ->when($actor->operating_branch_id, fn (Builder $q, int $id) => $q->where('operating_branch_id', $id))
            ->where('is_active', true)->with('operatingBranch')->orderBy('name')->get();
        $handledByUser = VisitFeedback::query()->whereIn('reviewed_by', $users->modelKeys())
            ->whereBetween('reviewed_at', [$from, $to])->get()->groupBy('reviewed_by');
        $actionsByUser = AuditLog::query()->whereIn('user_id', $users->modelKeys())->whereBetween('created_at', [$from, $to])
            ->where(function (Builder $q): void {
                $q->where('action', 'like', 'visit.%')->orWhere('action', 'like', 'visit_feedback.%')
                    ->orWhere('action', 'like', 'report_dispute.%')->orWhere('action', 'like', 'client_service_request.%')
                    ->orWhere('action', 'like', 'emergency.%')->orWhere('action', 'like', 'additional_work.%');
            })->select('user_id')->selectRaw('COUNT(*) AS total')->groupBy('user_id')->pluck('total', 'user_id');

        return $this->rank($users->map(function (User $user) use ($to, $settings, $periodVisits, $handledByUser, $actionsByUser): array {
            $visits = $periodVisits->filter(fn (Visit $visit) => $user->operating_branch_id === null
                || $visit->site?->client?->operating_branch_id === $user->operating_branch_id);
            $completed = $visits->where('state', Visit::STATE_COMPLETED);
            $sla = $completed->filter(fn (Visit $v) => $v->workOrder?->sla_due_at);
            $feedback = $completed->pluck('feedback')->filter();
            $handled = $handledByUser->get($user->id, collect());
            $actions = (int) ($actionsByUser->get($user->id) ?? 0);
            $asOf = $to->min(CarbonImmutable::now());
            $due = $visits->filter(fn (Visit $v) => $v->workOrder?->sla_due_at && $v->workOrder->sla_due_at->lte($asOf));
            $onTime = $due->filter(fn (Visit $v) => $v->state === Visit::STATE_COMPLETED && ($v->closed_at ?? $v->ended_at)?->lte($v->workOrder->sla_due_at));
            $handledFast = $handled->filter(fn (VisitFeedback $f) => $f->reviewed_at && $f->created_at->diffInHours($f->reviewed_at) <= 24);

            $values = [
                'team_sla' => [$this->percent($sla->filter(fn (Visit $v) => ($v->closed_at ?? $v->ended_at)?->lte($v->workOrder->sla_due_at))->count(), $sla->count()), $sla->count(), null],
                'team_first_time_fix' => [$this->percent($completed->where('is_rework', false)->count(), $completed->count()), $completed->count(), null],
                'team_rating' => [$feedback->count() ? (float) $feedback->avg('rating') * 20 : 0, $feedback->count(), $feedback->count() ? number_format((float) $feedback->avg('rating'), 1).'/5' : '—'],
                'complaint_response' => [$this->percent($handledFast->count(), $handled->count()), $handled->count(), null],
                'operational_actions' => [$actions, $actions, $actions.' إجراء'],
                'overdue_control' => [$this->percent($onTime->count(), $due->count()), $due->count(), null],
            ];

            return $this->entity($user->id, $user->name, $user->operatingBranch?->name ?? 'جميع الفروع', $values, $settings);
        })->all());
    }

    /** @return array<int, array<string, mixed>> */
    private function branches(User $actor, CarbonImmutable $from, CarbonImmutable $to, Collection $settings, Collection $periodVisits, array $visitCosts): array
    {
        $scope = (string) ($actor->operating_branch_id ?? 'all');
        $branches = $this->branchesByScope[$scope] ??= OperatingBranch::query()
            ->where('is_active', true)
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
            ->select('payment_clients.operating_branch_id')->selectRaw('SUM(payments.amount) AS total')
            ->groupBy('payment_clients.operating_branch_id')->pluck('total', 'payment_clients.operating_branch_id');

        return $this->rank($branches->map(function (OperatingBranch $branch) use ($settings, $periodVisits, $visitCosts, $installmentsByBranch, $revenueByBranch): array {
            $visits = $periodVisits->filter(fn (Visit $visit) => $visit->site?->client?->operating_branch_id === $branch->id);
            $completed = $visits->where('state', Visit::STATE_COMPLETED);
            $sla = $completed->filter(fn (Visit $v) => $v->workOrder?->sla_due_at);
            $feedback = $completed->pluck('feedback')->filter();
            $installments = $installmentsByBranch->get($branch->id);
            $due = (float) ($installments?->due_total ?? 0);
            $paid = (float) ($installments?->paid_total ?? 0);
            $installmentCount = (int) ($installments?->installment_count ?? 0);
            $revenue = (float) ($revenueByBranch->get($branch->id) ?? 0);
            $cost = (float) $completed->sum(fn (Visit $visit) => $visitCosts[$visit->id]['cost'] ?? 0);
            $margin = $revenue > 0 ? (($revenue - $cost) / $revenue) * 100 : 0;

            $values = [
                'sla_rate' => [$this->percent($sla->filter(fn (Visit $v) => ($v->closed_at ?? $v->ended_at)?->lte($v->workOrder->sla_due_at))->count(), $sla->count()), $sla->count(), null],
                'first_time_fix' => [$this->percent($completed->where('is_rework', false)->count(), $completed->count()), $completed->count(), null],
                'customer_rating' => [$feedback->count() ? (float) $feedback->avg('rating') * 20 : 0, $feedback->count(), $feedback->count() ? number_format((float) $feedback->avg('rating'), 1).'/5' : '—'],
                'collection_rate' => [$due > 0 ? min(100, ($paid / $due) * 100) : 0, $installmentCount, null],
                'profit_margin' => [$margin, $completed->count(), number_format($margin, 1).'%'],
                'completion_rate' => [$this->percent($completed->count(), $visits->count()), $visits->count(), null],
            ];

            return $this->entity($branch->id, $branch->name, $branch->company?->name ?? '—', $values, $settings);
        })->all());
    }

    /** @return array<int, array<string, mixed>> */
    private function marketers(User $actor, CarbonImmutable $from, CarbonImmutable $to, Collection $settings): array
    {
        $scope = (string) ($actor->operating_branch_id ?? 'all');
        $users = $this->marketersByScope[$scope] ??= (function () use ($actor): Collection {
            $ids = SalesLead::query()->whereNotNull('owner_user_id')->pluck('owner_user_id')
                ->merge(Quotation::query()->whereNotNull('created_by')->pluck('created_by'))->unique();

            return User::query()->whereIn('id', $ids)
                ->when($actor->operating_branch_id, fn (Builder $q, int $id) => $q->where('operating_branch_id', $id))
                ->with('operatingBranch')->get();
        })();
        $userIds = $users->modelKeys();
        $leadsByUser = SalesLead::query()->whereIn('owner_user_id', $userIds)
            ->whereBetween('created_at', [$from, $to])->get()->groupBy('owner_user_id');
        $pipelineByUser = SalesLead::query()->whereIn('owner_user_id', $userIds)
            ->whereIn('stage', ['new', 'qualified', 'proposal', 'negotiation'])->get()->groupBy('owner_user_id');
        $quotesByUser = Quotation::query()->whereIn('created_by', $userIds)
            ->whereBetween('created_at', [$from, $to])->get()->groupBy('created_by');
        $activitiesByUser = SalesActivity::query()->whereIn('user_id', $userIds)
            ->whereBetween('occurred_at', [$from, $to])->select('user_id')
            ->selectRaw('COUNT(*) AS total')->groupBy('user_id')->pluck('total', 'user_id');
        $collectionsByUser = Payment::query()
            ->join('contract_installments', 'contract_installments.id', '=', 'payments.contract_installment_id')
            ->join('contracts', 'contracts.id', '=', 'contract_installments.contract_id')
            ->join('quotations', 'quotations.id', '=', 'contracts.source_quotation_id')
            ->whereIn('quotations.created_by', $userIds)
            ->whereBetween('payments.paid_on', [$from->toDateString(), $to->toDateString()])
            ->select('quotations.created_by')->selectRaw('SUM(payments.amount) AS total')
            ->groupBy('quotations.created_by')->pluck('total', 'quotations.created_by');

        return $this->rank($users->map(function (User $user) use ($settings, $leadsByUser, $pipelineByUser, $quotesByUser, $activitiesByUser, $collectionsByUser): array {
            $leads = $leadsByUser->get($user->id, collect());
            $wonLeads = $leads->where('stage', 'won');
            $quotes = $quotesByUser->get($user->id, collect());
            $decided = $quotes->whereIn('status', ['accepted', 'converted', 'rejected']);
            $accepted = $quotes->whereIn('status', ['accepted', 'converted']);
            $activities = (int) ($activitiesByUser->get($user->id) ?? 0);
            $activePipeline = $pipelineByUser->get($user->id, collect());
            $collections = (float) ($collectionsByUser->get($user->id) ?? 0);

            $values = [
                'lead_conversion' => [$this->percent($wonLeads->count(), $leads->count()), $leads->count(), null],
                'quote_acceptance' => [$this->percent($accepted->count(), $decided->count()), $decided->count(), null],
                'won_value' => [(float) $accepted->sum('price_amount'), $accepted->count(), number_format((float) $accepted->sum('price_amount'), 0).' ر.س'],
                'collections' => [$collections, $collections > 0 ? 1 : 0, number_format($collections, 0).' ر.س'],
                'follow_up' => [$activities, $activities, $activities.' متابعة'],
                'pipeline_hygiene' => [$this->percent($activePipeline->whereNotNull('next_action_on')->count(), $activePipeline->count()), $activePipeline->count(), null],
            ];

            return $this->entity($user->id, $user->name, $user->operatingBranch?->name ?? 'الإدارة العامة', $values, $settings);
        })->all());
    }

    private function visitQuery(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId = null): Builder
    {
        return Visit::query()->whereBetween('scheduled_start', [$from, $to])
            ->when($branchId, fn (Builder $q, int $id) => $q->whereHas('site.client', fn (Builder $client) => $client->where('operating_branch_id', $id)))
            ->with(['workOrder', 'feedback', 'site.client']);
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
