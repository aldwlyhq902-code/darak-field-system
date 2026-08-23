<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Contract;
use App\Models\EmergencyReport;
use App\Models\Site;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\SlaCalculator;
use App\Support\BusinessReference;
use Carbon\CarbonImmutable;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmergencyReportController extends Controller
{
    public function __construct(
        private readonly SlaCalculator $sla,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function create(string $token): View
    {
        $site = $this->siteForToken($token);

        return view('emergency.create', [
            'site' => $site->load('assets'),
            'token' => $token,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $site = $this->siteForToken($token);
        abort_if($request->filled('company'), 422);

        $data = $request->validate([
            'reporter_name' => ['required', 'string', 'max:100'],
            'reporter_phone' => ['required', 'regex:/^(?:\+?966|0)?5\d{8}$/'],
            'reporter_role' => ['nullable', 'string', 'max:64'],
            'category' => ['required', 'in:ac,refrigeration,electrical,plumbing,gas,other'],
            'severity' => ['required', 'in:urgent,critical'],
            'description' => ['required', 'string', 'min:10', 'max:1500'],
            'asset_id' => [
                'nullable',
                Rule::exists('assets', 'id')->where('site_id', $site->id),
            ],
            'photo' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:8192'],
        ]);

        $photoPath = null;
        $photoMime = null;
        $photoSha = null;
        if ($request->hasFile('photo')) {
            $photo = $request->file('photo');
            $photoMime = $photo->getMimeType();
            $photoSha = hash_file('sha256', $photo->getRealPath());
            $photoPath = $photo->store('emergency-reports', 'local');
        }

        $report = EmergencyReport::create([
            ...Arr::except($data, ['photo']),
            'public_reference' => (string) Str::uuid(),
            'site_id' => $site->id,
            'client_portal_user_id' => auth('client')->id(),
            'photo_path' => $photoPath,
            'photo_mime' => $photoMime,
            'photo_sha256' => $photoSha,
            'status' => EmergencyReport::STATUS_NEW,
            'source' => auth('client')->check() ? 'client_portal' : 'site_qr',
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
        ]);

        $this->audit->record('emergency.reported', $report, null, [
            'site_id' => $site->id,
            'category' => $report->category,
            'severity' => $report->severity,
        ]);
        $this->notifications->emergencyReported($report->load('site.client'));

        return redirect()->route('emergency.received', $report->public_reference);
    }

    public function received(string $reference): View
    {
        $report = EmergencyReport::where('public_reference', $reference)->firstOrFail();

        return view('emergency.received', ['report' => $report]);
    }

    public function qr(Site $site)
    {
        $secret = $site->emergency_qr_secret;
        if ($secret === null) {
            $secret = $site->rotateEmergencyQr();
            $site->save();
        }

        $url = route('emergency.create', $secret);
        $result = Builder::create()
            ->writer(new SvgWriter)
            ->data($url)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size(420)
            ->margin(18)
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
            ->foregroundColor(new Color(5, 47, 44))
            ->backgroundColor(new Color(255, 255, 255))
            ->build();

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
            'Content-Disposition' => 'inline; filename="darak-emergency-site-'.$site->id.'.svg"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function sticker(Site $site): View
    {
        return view('panel.emergency-sticker', ['site' => $site->load('client')]);
    }

    public function index(): View
    {
        return view('panel.emergencies', [
            'reports' => EmergencyReport::with(['site.client', 'asset', 'workOrder'])
                ->latest()
                ->paginate(30),
        ]);
    }

    public function show(EmergencyReport $emergency): View
    {
        return view('panel.emergency', [
            'emergency' => $emergency->load(['site.client', 'asset', 'workOrder']),
        ]);
    }

    public function photo(EmergencyReport $emergency)
    {
        abort_if($emergency->photo_path === null || ! Storage::disk('local')->exists($emergency->photo_path), 404);

        return Storage::disk('local')->response($emergency->photo_path, null, [
            'Content-Type' => $emergency->photo_mime ?? 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function convert(EmergencyReport $emergency): RedirectResponse
    {
        if ($emergency->work_order_id !== null) {
            return back()->with('ok', 'البلاغ مرتبط بالفعل بأمر عمل.');
        }

        $visit = DB::transaction(function () use ($emergency): Visit {
            $emergency = EmergencyReport::query()->lockForUpdate()->findOrFail($emergency->id);
            if ($emergency->work_order_id !== null) {
                return $emergency->workOrder->visits()->firstOrFail();
            }

            $site = $emergency->site;
            $contract = Contract::where('client_id', $site->client_id)
                ->where('status', 'active')
                ->whereHas('sites', fn ($query) => $query->whereKey($site->id))
                ->latest('id')
                ->first();
            $reportedAt = CarbonImmutable::instance($emergency->created_at);
            $budget = $contract?->sla_minutes ?? 480;

            $workOrder = WorkOrder::create([
                'wo_number' => BusinessReference::make('WO', false),
                'client_id' => $site->client_id,
                'site_id' => $site->id,
                'contract_id' => $contract?->id,
                'asset_id' => $emergency->asset_id,
                'type' => $contract ? 'reactive' : 'out_of_contract',
                'priority' => 'urgent',
                'title' => 'بلاغ طارئ: '.$this->categoryName($emergency->category),
                'description' => $emergency->description."\nالمبلّغ: {$emergency->reporter_name} — {$emergency->reporter_phone}",
                'reported_at' => $reportedAt,
                'sla_minutes_budget' => $budget,
                'sla_due_at' => $this->sla->dueAt($reportedAt, $budget, $contract),
                'status' => 'scheduled',
                'created_by' => auth()->id(),
            ]);

            $start = CarbonImmutable::now();
            $requiredAssets = $emergency->asset_id !== null
                ? [$emergency->asset_id]
                : Asset::where('site_id', $site->id)->pluck('id')->all();
            $visit = Visit::create([
                'work_order_id' => $workOrder->id,
                'site_id' => $site->id,
                'required_asset_ids' => $requiredAssets,
                'scheduled_start' => $start,
                'scheduled_end' => $start->addHours(2),
                'state' => Visit::STATE_SCHEDULED,
                'state_changed_at' => $start,
            ]);

            $emergency->forceFill([
                'work_order_id' => $workOrder->id,
                'status' => EmergencyReport::STATUS_CONVERTED,
            ])->save();

            $this->audit->record('emergency.converted', $emergency, null, [
                'work_order_id' => $workOrder->id,
                'visit_id' => $visit->id,
            ]);

            return $visit;
        });

        return redirect()->route('panel.visit', $visit)
            ->with('ok', 'حُوّل البلاغ إلى زيارة عاجلة. حدّد الفني الآن.');
    }

    public function reject(Request $request, EmergencyReport $emergency): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        abort_if($emergency->work_order_id !== null, 422);
        $emergency->forceFill(['status' => EmergencyReport::STATUS_REJECTED])->save();
        $this->audit->record('emergency.rejected', $emergency, null, ['reason' => $data['reason']]);

        return redirect()->route('panel.emergencies')->with('ok', 'رُفض البلاغ مع تسجيل السبب.');
    }

    private function siteForToken(string $token): Site
    {
        return Site::with('client')
            ->where('emergency_public_token', hash('sha256', $token))
            ->where('is_active', true)
            ->whereHas('client', fn ($query) => $query->where('is_active', true))
            ->firstOrFail();
    }

    private function categoryName(string $category): string
    {
        return [
            'ac' => 'تكييف', 'refrigeration' => 'تبريد', 'electrical' => 'كهرباء',
            'plumbing' => 'سباكة', 'gas' => 'غاز', 'other' => 'أخرى',
        ][$category] ?? 'أخرى';
    }
}
