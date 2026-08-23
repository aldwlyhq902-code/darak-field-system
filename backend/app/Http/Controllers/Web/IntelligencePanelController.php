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
    public function __construct(private readonly AssetIntelligenceService $intelligence, private readonly AuditLogger $audit, private readonly FaultPredictionTrainer $trainer) {}

    public function index(): View
    {
        return view('panel.intelligence', [
            'assetHealth' => $this->intelligence->assetHealth()->take(50),
            'repeatParts' => $this->intelligence->repeatedClientParts(),
            'supplierFailures' => $this->intelligence->supplierFailures(),
            'readiness' => $this->intelligence->predictionReadiness(),
            'predictions' => $this->intelligence->failurePredictions(),
            'trainedModel' => FaultPredictionModel::with(['predictions' => fn ($query) => $query->with('asset.site.client')->orderByDesc('risk_score')->limit(30)])->where('is_active', true)->latest('trained_at')->first(),
            'articles' => KnowledgeArticle::latest()->limit(50)->get(),
            'parts' => Part::where('is_active', true)->orderBy('name')->get(),
            'lots' => InventoryLot::with(['part', 'supplier'])->latest()->limit(100)->get(),
            'assets' => Asset::with('site.client')->orderBy('name')->get(),
            'visits' => Visit::with('site.client')->latest('scheduled_start')->limit(100)->get(),
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
            'visit_id' => ['nullable', 'exists:visits,id'], 'asset_id' => ['nullable', 'exists:assets,id'],
            'failure_kind' => ['required', 'string', 'max:48'], 'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $lot = isset($data['inventory_lot_id']) ? InventoryLot::find($data['inventory_lot_id']) : null;
        $failure = PartFailureReport::create($data + ['supplier_id' => $lot?->supplier_id, 'reported_by' => $request->user()->id]);
        $this->audit->record('part.failure_reported', $failure, null, $failure->toArray(), $request->user()->id);

        return back()->with('ok', 'سُجل فشل القطعة وربط بالمورد والدفعة عند توفرهما.');
    }
}
