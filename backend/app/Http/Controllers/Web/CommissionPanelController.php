<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CommissionEntry;
use App\Models\CommissionPayout;
use App\Models\User;
use App\Services\CommissionService;
use App\Support\Money;
use App\Support\TenantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** Commission ledger and owner payouts (accrued → earned → paid, or reversed). */
class CommissionPanelController extends Controller
{
    public function __construct(
        private readonly CommissionService $commissions,
        private readonly TenantAccess $tenantAccess,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $status = $request->query('status');
        $status = in_array($status, array_keys(CommissionEntry::STATUS_LABELS), true) ? $status : null;

        // Totals come from aggregates over ALL visible rows, never the page.
        $totals = $this->visibleEntries($actor)
            ->where('entry_type', CommissionEntry::TYPE_COMMISSION)
            ->selectRaw('status, COUNT(*) AS entries, COALESCE(SUM(commission_amount), 0) AS total')
            ->groupBy('status')->get()->keyBy('status');
        $outstandingAdjustments = $this->visibleEntries($actor)
            ->where('entry_type', CommissionEntry::TYPE_ADJUSTMENT)
            ->where('status', CommissionEntry::STATUS_EARNED)
            ->selectRaw('COALESCE(SUM(-commission_amount - offset_amount), 0) AS total')
            ->value('total');

        return view('panel.commissions', [
            'status' => $status,
            'totals' => collect(CommissionEntry::STATUS_LABELS)->map(fn (string $label, string $key) => [
                'label' => $label,
                'entries' => (int) ($totals->get($key)?->entries ?? 0),
                'total' => Money::of($totals->get($key)?->total ?? 0),
            ]),
            'outstandingAdjustments' => Money::of($outstandingAdjustments),
            'windowDays' => $this->commissions->windowDays(),
            'entries' => $this->visibleEntries($actor)->with(['user', 'rule', 'payout'])
                ->when($status, fn (Builder $query, string $status) => $query->where('status', $status))
                ->latest('id')->paginate(40, ['*'], 'entries_page')->withQueryString(),
            'payable' => $this->visibleEntries($actor)->with(['user', 'rule'])
                ->where('entry_type', CommissionEntry::TYPE_COMMISSION)
                ->where('status', CommissionEntry::STATUS_EARNED)
                ->orderBy('user_id')->orderBy('id')
                ->paginate(50, ['*'], 'payable_page')->withQueryString(),
            'payouts' => CommissionPayout::query()->with(['user', 'recorder'])
                ->whereIn('user_id', $this->visibleUsers($actor)->select('users.id'))
                ->latest('id')->paginate(20, ['*'], 'payouts_page')->withQueryString(),
            'canPay' => $actor->isOwner(),
        ]);
    }

    public function payout(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->isOwner(), 403);
        $data = $request->validate([
            'entry_ids' => ['required', 'array', 'min:1', 'max:500'],
            'entry_ids.*' => ['integer', Rule::exists('commission_entries', 'id')],
            'reference' => ['required', 'string', 'max:96'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $requested = array_values(array_unique(array_map('intval', $data['entry_ids'])));
        abort_unless($this->visibleEntries($actor)->whereIn('id', $requested)->count() === count($requested), 404);

        try {
            $payouts = $this->commissions->recordPayout(
                $data['entry_ids'], $data['reference'], CarbonImmutable::parse($data['paid_on']), $actor,
                fn (User $beneficiary): bool => $this->mayPay($actor, $beneficiary),
                $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        $net = Money::sum(array_map(fn ($payout) => $payout->net_amount, $payouts));

        return back()->with('ok', 'سُجل صرف '.count($payouts).' دفعة عمولة بصافي '.Money::format($net).' ر.س.');
    }

    private function visibleEntries(User $actor): Builder
    {
        return CommissionEntry::query()->whereIn('user_id', $this->visibleUsers($actor)->select('users.id'));
    }

    private function visibleUsers(User $actor): Builder
    {
        return User::query()
            ->when(! $actor->isPlatformAdmin(), fn (Builder $query) => $query
                ->where('operating_company_id', $actor->operating_company_id ?? 0)
                ->when($actor->operating_branch_id, fn (Builder $users, int $branchId) => $users->where('operating_branch_id', $branchId)));
    }

    private function mayPay(User $actor, User $beneficiary): bool
    {
        return $this->tenantAccess->allowsCompany($actor, $beneficiary->operating_company_id === null ? null : (int) $beneficiary->operating_company_id)
            && ($actor->operating_branch_id === null || (int) $beneficiary->operating_branch_id === (int) $actor->operating_branch_id);
    }
}
