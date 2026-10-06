<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FaultPredictionModel;
use App\Models\InventoryLot;
use App\Models\KnowledgeArticle;
use App\Models\Part;
use App\Models\PartFailureReport;
use App\Models\Visit;
use App\Services\AssetIntelligenceService;
use App\Services\AuditLogger;
use App\Services\FaultPredictionTrainer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class IntelligencePanelController extends Controller
{
    private const WORST_ASSETS = 50;

    private const TOP_PREDICTIONS = 10;

    public function __construct(private readonly AssetIntelligenceService $intelligence, private readonly AuditLogger $audit, private readonly FaultPredictionTrainer $trainer) {}

    public function index(): View
    {
        return view('panel.intelligence', [
            // A deliberate "worst N" widget (sorted by health), labelled as such.
            'assetHealth' => $this->intelligence->assetHealth()->take(self::WORST_ASSETS),
            'worstAssets' => self::WORST_ASSETS,
            'repeatParts' => $this->intelligence->repeatedClientParts(),
            'supplierFailures' => $this->intelligence->supplierFailures(),
            'readiness' => $this->intelligence->predictionReadiness(),
            'predictions' => $this->intelligence->failurePredictions(),
            // Top riskiest assets only (labelled); the full table stays queryable.
            'trainedModel' => FaultPredictionModel::with(['predictions' => fn ($query) => $query->with('asset.site.client')->orderByDesc('risk_score')->limit(self::TOP_PREDICTIONS)])->where('is_active', true)->latest('trained_at')->first(),
            'topPredictions' => self::TOP_PREDICTIONS,
            'articles' => KnowledgeArticle::latest()->latest('id')->paginate(20, ['*'], 'articles_page')->withQueryString(),
            'parts' => Part::where('is_active', true)->orderBy('name')->get(),
            // Lots, assets and visits are referenced by serial/QR/number in the
            // failure form instead of selects that were capped at 100 rows.
        ]);
    }

    public function train(): RedirectResponse
    {
        try {
            $model = $this->trainer->train();
        } catch (RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }

        return back()->with('ok', "تدرب النموذج واختُبر على بيانات منفصلة؛ الدقة {$model->accuracy} والاستدعاء {$model->recall}.");
    }

    public function article(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fault_code' => ['nullable', 'string', 'max:64'], 'asset_type' => ['nullable', 'string', 'max:32'],
            'title' => ['required', 'string', 'max:255'], 'symptoms' => ['nullable', 'string', 'max:4000'],
            'diagnosis' => ['required', 'string', 'max:8000'], 'solution' => ['required', 'string', 'max:8000'],
            'suggested_part_ids' => ['nullable', 'array'], 'suggested_part_ids.*' => ['exists:parts,id'],
        ]);
        $article = KnowledgeArticle::create($data + ['is_published' => true, 'created_by' => $request->user()->id]);
        $this->audit->record('knowledge.created', $article, null, $article->toArray(), $request->user()->id);

        return back()->with('ok', 'أُضيف الحل إلى قاعدة المعرفة وأصبح متاحًا للاقتراح التشخيصي.');
    }

    public function failure(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'part_id' => ['required', 'exists:parts,id'], 'inventory_lot_id' => ['nullable', 'exists:inventory_lots,id'],
            'lot_ref' => ['nullable', 'string', 'max:96'], 'asset_ref' => ['nullable', 'string', 'max:96'],
            'visit_id' => ['nullable', 'exists:visits,id'], 'asset_id' => ['nullable', 'exists:assets,id'],
            'failure_kind' => ['required', 'string', 'max:48'], 'note' => ['nullable', 'string', 'max:2000'],
        ]);
        // Typed references resolve through the tenant scopes.
        if (filled($data['lot_ref'] ?? null)) {
            $ref = trim($data['lot_ref']);
            $data['inventory_lot_id'] = InventoryLot::query()->where('part_id', $data['part_id'])
                ->where(fn ($q) => $q->where('serial_number', $ref)->orWhere('lot_number', $ref))->firstOrFail()->id;
        }
        if (filled($data['asset_ref'] ?? null)) {
            $data['asset_id'] = Asset::query()->where('qr_code', trim($data['asset_ref']))->firstOrFail()->id;
        }
        if (isset($data['visit_id'])) {
            Visit::query()->findOrFail($data['visit_id']);
        }
        unset($data['lot_ref'], $data['asset_ref']);
        $lot = isset($data['inventory_lot_id']) ? InventoryLot::query()->findOrFail($data['inventory_lot_id']) : null;
        $failure = PartFailureReport::create($data + ['supplier_id' => $lot?->supplier_id, 'reported_by' => $request->user()->id]);
        $this->audit->record('part.failure_reported', $failure, null, $failure->toArray(), $request->user()->id);

        return back()->with('ok', 'سُجل فشل القطعة وربط بالمورد والدفعة عند توفرهما.');
    }
}
