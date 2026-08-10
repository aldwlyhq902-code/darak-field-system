<?php

namespace App\Services;

use App\Models\Visit;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;

/**
 * The Arabic RTL visit report — the single PDF the MVP produces.
 *
 * Wording constraint (PRD v1.2 §3.5): Darak sells operations management and
 * supplier coordination. The report is a TECHNICAL CONDITION REPORT and a
 * MAINTENANCE RECORD. It must never read as an approval, a compliance certificate,
 * or a licence requirement — official certificates come from accredited parties and
 * are attached under the ISSUING party's name.
 *
 * The templates are locked and versioned for that reason. There is deliberately no
 * banned-word engine: a word list is not legal protection, and it would block
 * legitimate text while missing the same meaning phrased differently.
 */
class VisitReportBuilder
{
    public const TEMPLATE_VERSION = 'visit-report-v1';

    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function render(Visit $visit): string
    {
        $visit->loadMissing([
            'workOrder.client', 'workOrder.contract', 'workOrder.asset',
            'site.client', 'checklistInstances.asset', 'mediaFiles',
            'stockMoves.part', 'technician',
        ]);

        $html = View::make('reports.visit', [
            'visit' => $visit,
            'parts' => $this->inventory->visitConsumption($visit),
            'photos' => $visit->mediaFiles
                ->whereIn('kind', ['photo_before', 'photo_after'])
                ->where('upload_state', 'complete'),
            'signature' => $visit->mediaFiles->firstWhere('kind', 'signature'),
            'templateVersion' => self::TEMPLATE_VERSION,
        ])->render();

        // Arabic shaping notes, since getting this wrong produces a report of
        // disconnected letters that looks fine in code review and is unusable:
        //  - useOTL must be on for the font (dejavusans and xbriyaz both ship with
        //    0xFF in mPDF's defaults) or letters will not join.
        //  - xbriyaz is mPDF's bundled Arabic face; DejaVu covers Arabic but reads
        //    poorly at body size.
        //  - autoScriptToLang/autoLangToFont let a mixed line (Arabic labels with
        //    Latin SKUs and dates) pick the right face per run.
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'directionality' => 'rtl',
            'default_font' => 'xbriyaz',
            'useOTL' => 0xFF,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => storage_path('app/mpdf'),
            'margin_top' => 12,
            'margin_bottom' => 14,
        ]);

        $mpdf->SetDirectionality('rtl');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    /** Renders and stores, returning the storage path. */
    public function store(Visit $visit): string
    {
        $path = "reports/visits/visit-{$visit->id}.pdf";
        Storage::disk('local')->put($path, $this->render($visit));

        return $path;
    }

    public function imageDataUri(?string $storagePath): ?string
    {
        if ($storagePath === null) {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($storagePath)) {
            return null;
        }

        $bytes = $disk->get($storagePath);
        $mime = str_ends_with($storagePath, '.png') ? 'image/png' : 'image/jpeg';

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
