<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CommissionEntry;
use App\Models\Contact;
use App\Models\FinancialApproval;
use App\Models\NotificationMessage;
use App\Models\Payment;
use App\Models\PerformanceMetricSetting;
use App\Models\Quotation;
use App\Models\SalesActivity;
use App\Models\SalesLead;
use App\Models\SalesLeadAttachment;
use App\Models\SalesTarget;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PerformanceScoreService;
use App\Services\SalesPushService;
use App\Support\TenantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesPortalController extends Controller
{
    private const ACTIVE_STAGES = ['new', 'qualified', 'proposal', 'negotiation'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PerformanceScoreService $performance,
        private readonly SalesPushService $push,
        private readonly TenantAccess $tenantAccess,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $leadQuery = $this->ownedLeads($user)->with(['owner', 'attachments', 'convertedClient', 'activities' => fn ($q) => $q->latest('occurred_at')]);
        $leads = (clone $leadQuery)->orderByRaw('CASE WHEN next_action_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_action_on')->latest('updated_at')->get();
        $quoteQuery = $this->ownedQuotations($user)->with('client');
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $monthlyQuotes = (clone $quoteQuery)->whereBetween('created_at', [$monthStart, $monthEnd])->get();
        $wonQuotes = $monthlyQuotes->whereIn('status', [Quotation::STATUS_ACCEPTED, Quotation::STATUS_CONVERTED]);
        $monthlyLeads = $leads->filter(fn (SalesLead $lead) => $lead->created_at->between($monthStart, $monthEnd));
        $dueFollowUps = $leads->whereIn('stage', self::ACTIVE_STAGES)
            ->filter(fn (SalesLead $lead) => $lead->next_action_on?->lte(today()));
        $score = $this->performance->marketerScore(
            $user, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth(),
        );
        $commissions = CommissionEntry::query()->where('user_id', $user->id)
            ->whereBetween('created_at', [$monthStart, $monthEnd])->get();
        $collections = (float) Payment::query()->whereBetween('paid_on', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereHas('installment.contract.sourceQuotation', fn (Builder $query) => $query->where('created_by', $user->id))->sum('amount');
        $monthActivities = SalesActivity::query()->where('user_id', $user->id)->whereBetween('occurred_at', [$monthStart, $monthEnd])->get();
        $target = SalesTarget::query()->firstOrNew(['user_id' => $user->id, 'month' => $monthStart->toDateString()], [
            'calls_target' => 40, 'meetings_target' => 12, 'proposals_target' => 8,
            'won_value_target' => 100000, 'collections_target' => 75000,
        ]);
        $staleCutoff = now()->subDays(max(1, (int) config('sales.stale_after_days')));
        $staleLeads = $leads->whereIn('stage', self::ACTIVE_STAGES)->filter(fn (SalesLead $lead) => ($lead->last_contacted_at ?? $lead->created_at)->lte($staleCutoff));
        $teamScores = $user->isOwner() ? $this->performance->marketerScores($user, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth()) : [];

        return view('sales.dashboard', [
            'leads' => $leads, 'pipeline' => $leads->groupBy('stage'),
            'quotations' => (clone $quoteQuery)->latest()->limit(30)->get(),
            'clients' => Client::query()->where('is_active', true)->with('sites')->orderBy('name')->get(),
            'activities' => SalesActivity::query()->whereIn('sales_lead_id', $leads->pluck('id'))->with('lead')->latest('occurred_at')->limit(12)->get(),
            'dueFollowUps' => $dueFollowUps,
            'calendar' => $leads->whereIn('stage', self::ACTIVE_STAGES)->whereNotNull('next_action_on')->filter(fn (SalesLead $lead) => $lead->next_action_on->between(today(), today()->addDays(30)))->groupBy(fn (SalesLead $lead) => $lead->next_action_on->toDateString()),
            'staleLeads' => $staleLeads, 'salesTarget' => $target, 'teamScores' => $teamScores,
            'salesUsers' => $user->isOwner() ? User::query()->where('is_active', true)->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])->get()->filter(fn (User $member) => $member->canPanel('sales')) : collect(),
            'sourceBreakdown' => $user->isOwner() ? $leads->groupBy('source')->map(fn ($rows) => ['count' => $rows->count(), 'won' => $rows->where('stage', 'won')->count(), 'value' => (float) $rows->where('stage', 'won')->sum('estimated_value')]) : collect(),
            'salesNotifications' => NotificationMessage::query()->where('user_id', $user->id)->where('type', NotificationMessage::TYPE_SALES_ALERT)->latest()->limit(10)->get(),
            'pushConfigured' => $this->push->configured(), 'pushPublicKey' => config('sales.vapid.public_key'),
            'pushSubscribed' => $user->webPushSubscriptions()->exists(),
            'stageLabels' => $this->stageLabels(),
            'targets' => PerformanceMetricSetting::query()->where('category', 'marketers')->pluck('target', 'metric_key'),
            'kpis' => [
                'active' => $leads->whereIn('stage', self::ACTIVE_STAGES)->count(),
                'pipeline_value' => (float) $leads->whereIn('stage', self::ACTIVE_STAGES)->sum('estimated_value'),
                'won_value' => (float) $wonQuotes->sum('price_amount'),
                'conversion' => $monthlyLeads->count() ? round($monthlyLeads->where('stage', 'won')->count() / $monthlyLeads->count() * 100, 1) : 0,
                'collections' => $collections,
                'commissions' => (float) $commissions->sum('commission_amount'),
                'performance' => $score['score'] ?? 50,
                'rank' => $score['rank'] ?? null,
                'calls' => $monthActivities->where('type', 'call')->count(),
                'meetings' => $monthActivities->where('type', 'meeting')->count(),
                'proposals' => $monthActivities->where('type', 'proposal')->count(),
            ],
        ]);
    }

    public function storeLead(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:190'], 'contact_name' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'], 'email' => ['nullable', 'email:rfc', 'max:190'],
            'estimated_value' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'source' => ['required', 'in:direct,referral,google,social,campaign,field,partner'],
            'campaign_name' => ['nullable', 'required_if:source,campaign', 'string', 'max:190'],
            'expected_close_on' => ['nullable', 'date', 'after_or_equal:today'],
            'next_action_on' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $duplicate = SalesLead::query()->whereIn('stage', self::ACTIVE_STAGES)->where(function (Builder $query) use ($data): void {
            $query->whereRaw('LOWER(company_name) = ?', [mb_strtolower($data['company_name'])]);
            if (filled($data['phone'] ?? null)) {
                $query->orWhere('phone', $data['phone']);
            }
            if (filled($data['email'] ?? null)) {
                $query->orWhere('email', $data['email']);
            }
        })->first();
        if ($duplicate) {
            return back()->withInput()->with('err', "توجد فرصة نشطة مشابهة برقم {$duplicate->lead_no}؛ راجعها قبل إنشاء سجل مكرر.");
        }
        $lead = SalesLead::create($data + [
            'lead_no' => 'LEAD-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'stage' => 'new', 'owner_user_id' => $request->user()->id,
            'operating_branch_id' => $request->user()->operating_branch_id, 'probability_percent' => 10,
        ]);
        $this->audit->record('sales.lead_created', $lead, null, $lead->getAttributes(), $request->user()->id);

        return back()->with('ok', 'أُضيف العميل المحتمل إلى مسارك.');
    }

    public function activity(Request $request, SalesLead $lead): RedirectResponse
    {
        $this->authorizeLead($request->user(), $lead);
        $data = $request->validate([
            'type' => ['required', 'in:call,email,meeting,note,proposal'],
            'note' => ['required', 'string', 'max:3000'], 'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'stage' => ['required', 'in:new,qualified,proposal,negotiation,won,lost'],
            'next_action_on' => ['nullable', 'date'],
            'expected_close_on' => ['nullable', 'date'],
        ]);
        $before = $lead->only(['stage', 'next_action_on']);
        DB::transaction(function () use ($lead, $data, $request): void {
            $lead->activities()->create([
                'type' => $data['type'], 'note' => $data['note'],
                'occurred_at' => $data['occurred_at'], 'user_id' => $request->user()->id,
            ]);
            $lead->forceFill([
                'stage' => $data['stage'], 'next_action_on' => $data['next_action_on'] ?? null,
                'expected_close_on' => $data['expected_close_on'] ?? $lead->expected_close_on,
                'last_contacted_at' => $data['occurred_at'],
                'probability_percent' => $this->probability($data['stage'], $lead->activities()->count()),
            ])->save();
        });
        $this->audit->record('sales.follow_up_recorded', $lead, $before, $lead->only(['stage', 'next_action_on']), $request->user()->id);

        return back()->with('ok', 'سُجلت المتابعة وحُدثت مرحلة الفرصة.');
    }

    public function convert(Request $request, SalesLead $lead): RedirectResponse
    {
        $this->authorizeLead($request->user(), $lead);
        abort_if($lead->converted_client_id !== null, 422, 'سبق تحويل هذه الفرصة إلى عميل.');
        $data = $request->validate([
            'category' => ['required', 'in:restaurant,cafe,central_kitchen,chain'],
            'payment_term' => ['required', 'in:monthly_card,quarterly_advance'],
            'site_name' => ['required', 'string', 'max:190'], 'site_address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'], 'position' => ['nullable', 'string', 'max:64'],
        ]);
        $existing = Client::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($lead->company_name)])->first();
        if ($existing) {
            throw ValidationException::withMessages(['site_name' => "يوجد عميل مسجل بالاسم نفسه: {$existing->name}."]);
        }
        $branch = $lead->operatingBranch;
        $client = DB::transaction(function () use ($lead, $data, $branch): Client {
            $client = Client::create([
                'name' => $lead->company_name, 'commercial_name' => $lead->company_name,
                'category' => $data['category'], 'payment_term' => $data['payment_term'],
                'notes' => $lead->notes, 'is_active' => true,
                'operating_company_id' => $branch?->operating_company_id,
                'operating_branch_id' => $lead->operating_branch_id,
            ]);
            $site = Site::create(['client_id' => $client->id, 'name' => $data['site_name'], 'address' => trim(($data['site_address'] ?? '').' '.($data['city'] ?? '')), 'is_active' => true]);
            if (filled($lead->contact_name)) {
                Contact::create(['client_id' => $client->id, 'site_id' => $site->id, 'name' => $lead->contact_name, 'phone' => $lead->phone, 'email' => $lead->email, 'position' => $data['position'] ?? null, 'can_approve' => true]);
            }
            $lead->forceFill(['stage' => 'won', 'probability_percent' => 100, 'converted_client_id' => $client->id, 'converted_at' => now()])->save();

            return $client;
        });
        $this->audit->record('sales.lead_converted', $lead, null, ['client_id' => $client->id], $request->user()->id);

        return back()->with('ok', "حُولت الفرصة إلى العميل {$client->name} مع الموقع وجهة التواصل.");
    }

    public function attachment(Request $request, SalesLead $lead): RedirectResponse
    {
        $this->authorizeLead($request->user(), $lead);
        $data = $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,png,jpg,jpeg,webp,m4a,mp3,wav,ogg,webm']]);
        $file = $data['file'];
        $mime = (string) $file->getMimeType();
        $kind = str_starts_with($mime, 'audio/') ? 'audio' : (str_starts_with($mime, 'image/') ? 'image' : 'document');
        $path = $file->store("sales-leads/{$lead->id}", 'local');
        abort_if($path === false, 500, 'تعذر حفظ المرفق.');
        $attachment = SalesLeadAttachment::create([
            'sales_lead_id' => $lead->id, 'uploaded_by' => $request->user()->id,
            'kind' => $kind, 'disk' => 'local', 'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 190, ''),
            'mime_type' => $mime, 'size_bytes' => $file->getSize(),
        ]);
        $this->audit->record('sales.attachment_added', $attachment, null, $attachment->only(['sales_lead_id', 'kind', 'mime_type', 'size_bytes']), $request->user()->id);

        return back()->with('ok', 'حُفظ المرفق في التخزين الخاص بالمنشأة.');
    }

    public function downloadAttachment(Request $request, SalesLeadAttachment $attachment): StreamedResponse
    {
        $lead = $attachment->lead;
        abort_unless($lead !== null, 404);
        $this->authorizeLead($request->user(), $lead);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type, 'Content-Disposition' => str_starts_with($attachment->mime_type, 'audio/') || str_starts_with($attachment->mime_type, 'image/') ? 'inline' : 'attachment',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function target(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isOwner(), 403);
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'], 'month' => ['required', 'date_format:Y-m'],
            'calls_target' => ['required', 'integer', 'min:0', 'max:10000'], 'meetings_target' => ['required', 'integer', 'min:0', 'max:10000'],
            'proposals_target' => ['required', 'integer', 'min:0', 'max:10000'], 'won_value_target' => ['required', 'numeric', 'min:0'],
            'collections_target' => ['required', 'numeric', 'min:0'],
        ]);
        $targetUser = User::query()->findOrFail($data['user_id']);
        $this->tenantAccess->assertUser($request->user(), $targetUser);
        $values = collect($data)->except(['user_id', 'month'])->all() + ['set_by' => $request->user()->id];
        $target = SalesTarget::query()->updateOrCreate(['user_id' => $data['user_id'], 'month' => $data['month'].'-01'], $values);
        $this->audit->record('sales.target_set', $target, null, $target->getAttributes(), $request->user()->id);

        return back()->with('ok', 'حُفظت أهداف المسوق للشهر المحدد.');
    }

    public function requestDiscount(Request $request, Quotation $quotation): RedirectResponse
    {
        abort_unless($request->user()->isOwner() || $quotation->created_by === $request->user()->id, 403);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'lt:'.$quotation->price_amount], 'reason' => ['required', 'string', 'max:2000']]);
        FinancialApproval::create([
            'public_reference' => (string) Str::uuid(), 'action_type' => 'discount',
            'subject_type' => 'quotation', 'subject_id' => $quotation->id,
            'amount' => $data['amount'], 'reason' => $data['reason'], 'status' => 'pending_first',
            'requested_by' => $request->user()->id,
        ]);

        return back()->with('ok', 'أُرسل طلب الخصم للاعتماد الثنائي دون تعديل العرض.');
    }

    public function subscribePush(Request $request): JsonResponse
    {
        abort_unless($this->push->configured(), 422, 'Web Push is not configured.');
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2048'], 'keys.p256dh' => ['required', 'string', 'max:512'],
            'keys.auth' => ['required', 'string', 'max:256'], 'contentEncoding' => ['nullable', 'in:aesgcm,aes128gcm'],
        ]);
        $this->push->subscribe($request->user(), $data);

        return response()->json(['ok' => true]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $after = max(0, $request->integer('after'));
        $messages = NotificationMessage::query()->where('user_id', $request->user()->id)
            ->where('type', NotificationMessage::TYPE_SALES_ALERT)->where('id', '>', $after)->orderBy('id')->limit(20)->get(['id', 'subject', 'body', 'context', 'created_at']);

        return response()->json(['data' => $messages]);
    }

    public function storeQuotation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer'], 'site_ids' => ['required', 'array', 'min:1'],
            'site_ids.*' => ['integer'], 'title' => ['required', 'string', 'max:190'],
            'package_code' => ['required', 'in:basic,comprehensive'], 'price_amount' => ['required', 'numeric', 'min:0'],
            'billing_cycle' => ['required', 'in:monthly,quarterly,upfront'], 'duration_months' => ['required', 'integer', 'min:1', 'max:60'],
            'starts_on' => ['required', 'date'], 'valid_until' => ['required', 'date', 'after_or_equal:today'],
            'service_window_start' => ['required', 'date_format:H:i'], 'service_window_end' => ['required', 'date_format:H:i', 'after:service_window_start'],
            'sla_minutes' => ['required', 'integer', 'min:30', 'max:2880'], 'terms_text' => ['nullable', 'string', 'max:4000'],
        ]);
        $client = Client::query()->whereKey($data['client_id'])->firstOrFail();
        abort_unless($client->sites()->whereIn('id', $data['site_ids'])->count() === count(array_unique($data['site_ids'])), 422, 'أحد المواقع لا يتبع العميل المحدد.');

        $quotation = DB::transaction(function () use ($data, $request, $client): Quotation {
            $quote = Quotation::create([
                'series_uuid' => (string) Str::uuid(), 'version' => 1,
                'quote_no' => 'QT-'.now()->format('ym').'-'.Str::upper(Str::random(6)).'-V1',
                'client_id' => $client->id, 'title' => $data['title'], 'package_code' => $data['package_code'],
                'price_amount' => $data['price_amount'], 'vat_rate' => .15, 'billing_cycle' => $data['billing_cycle'],
                'duration_months' => $data['duration_months'], 'starts_on' => $data['starts_on'], 'valid_until' => $data['valid_until'],
                'service_window_start' => $data['service_window_start'], 'service_window_end' => $data['service_window_end'],
                'sla_minutes' => $data['sla_minutes'], 'status' => Quotation::STATUS_DRAFT,
                'terms' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $data['terms_text'] ?? '') ?: []))),
                'created_by' => $request->user()->id,
            ]);
            $quote->sites()->sync($data['site_ids']);

            return $quote;
        });
        $this->audit->record('sales.quotation_created', $quotation, null, $quotation->only(['quote_no', 'client_id', 'price_amount']), $request->user()->id);

        return back()->with('ok', "أُنشئ العرض {$quotation->quote_no} كمسودة جاهزة للمراجعة والإرسال.");
    }

    public function sendQuotation(Request $request, Quotation $quotation): RedirectResponse
    {
        abort_unless($request->user()->isOwner() || $quotation->created_by === $request->user()->id, 403);
        abort_unless($quotation->status === Quotation::STATUS_DRAFT, 422, 'لا يمكن إرسال هذا العرض في حالته الحالية.');
        $quotation->forceFill(['status' => Quotation::STATUS_SENT, 'sent_at' => now()])->save();
        $this->audit->record('quotation.sent', $quotation, null, ['source' => 'sales_pwa'], $request->user()->id);

        return back()->with('ok', 'أُرسل العرض وأصبح متاحًا لاعتماد العميل من بوابته.');
    }

    private function ownedLeads(User $user): Builder
    {
        return SalesLead::query()->when(! $user->isOwner(), fn (Builder $query) => $query->where('owner_user_id', $user->id));
    }

    private function ownedQuotations(User $user): Builder
    {
        return Quotation::query()->when(! $user->isOwner(), fn (Builder $query) => $query->where('created_by', $user->id));
    }

    private function authorizeLead(User $user, SalesLead $lead): void
    {
        abort_unless($user->isOwner() || $lead->owner_user_id === $user->id, 403);
    }

    private function probability(string $stage, int $activityCount): int
    {
        $base = ['new' => 10, 'qualified' => 30, 'proposal' => 55, 'negotiation' => 75, 'won' => 100, 'lost' => 0][$stage];

        return in_array($stage, ['won', 'lost'], true) ? $base : min(95, $base + min(15, $activityCount * 3));
    }

    /** @return array<string, string> */
    private function stageLabels(): array
    {
        return ['new' => 'جديد', 'qualified' => 'مؤهل', 'proposal' => 'عرض سعر', 'negotiation' => 'تفاوض', 'won' => 'مكتسب', 'lost' => 'مفقود'];
    }
}
