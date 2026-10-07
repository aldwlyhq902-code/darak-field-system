<?php

namespace App\Services;

use App\Models\Visit;
use App\Support\PrivateFiles;
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

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly RequiredAssets $requiredAssets,
        private readonly ReportImages $images,
    ) {}

    public function render(Visit $visit): string
    {
        $visit->loadMissing([
            'workOrder.client', 'workOrder.contract', 'workOrder.asset',
            'site.client', 'checklistInstances.asset', 'mediaFiles',
            'stockMoves.part', 'technician',
        ]);

        // Image bytes travel to mPDF beside the HTML (imageVars, referenced as
        // `var:<name>`), as report-sized derivatives. Inlined as full-size
        // base64 they made the HTML too large for mPDF once a visit had a few
        // real photos.
        $imageVars = [];
        $photoRefs = [];

        $photos = $visit->mediaFiles
            ->whereIn('kind', ['photo_before', 'photo_after'])
            ->where('upload_state', 'complete');

        foreach ($photos as $photo) {
            $ref = $this->addImage($imageVars, 'photo'.$photo->id, $photo->derived_path ?? $photo->original_path);

            if ($ref !== null) {
                $photoRefs[] = $ref;
            }
        }

        $signature = $visit->mediaFiles->firstWhere('kind', 'signature');

        $html = View::make('reports.visit', [
            'visit' => $visit,
            'parts' => $this->inventory->visitConsumption($visit),
            'photoRefs' => $photoRefs,
            'signature' => $signature,
            'signatureRef' => $signature ? $this->addImage($imageVars, 'signature', $signature->original_path) : null,
            // Units scheduled for this visit that were removed from the site
            // before it ran. Without them the report reads as a complete round.
            'retiredAssets' => $this->requiredAssets->retiredSince($visit),
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
            // Room for the template/page footer (reports.visit <htmlpagefooter>),
            // which lives in the bottom margin rather than in the content flow.
            'margin_bottom' => 18,
            'margin_footer' => 6,
        ]);

        $mpdf->SetDirectionality('rtl');
        $mpdf->imageVars = $imageVars;
        unset($imageVars);

        $this->withBacktrackLimitFor($html, fn () => $mpdf->WriteHTML($html));

        return $mpdf->Output('', 'S');
    }

    /** @param  array<string, string>  $imageVars */
    private function addImage(array &$imageVars, string $name, ?string $storagePath): ?string
    {
        $bytes = $storagePath === null ? null : $this->images->jpeg($storagePath);

        if ($bytes === null) {
            return null;
        }

        $imageVars[$name] = $bytes;

        return 'var:'.$name;
    }

    /**
     * mPDF refuses HTML longer than `pcre.backtrack_limit`. With the images
     * out of the HTML that takes an extraordinary report, but if one comes,
     * raise the limit for this render only and put it back afterwards — never
     * globally.
     *
     * @template T
     *
     * @param  callable(): T  $render
     * @return T
     */
    private function withBacktrackLimitFor(string $html, callable $render): mixed
    {
        $previous = ini_get('pcre.backtrack_limit');
        $needed = strlen($html) * 2;
        $raised = $previous !== false && (int) $previous < $needed
            && ini_set('pcre.backtrack_limit', (string) $needed) !== false;

        try {
            return $render();
        } finally {
            if ($raised) {
                ini_set('pcre.backtrack_limit', (string) $previous);
            }
        }
    }

    /** Renders and stores, returning the storage path. */
    public function store(Visit $visit): string
    {
        $path = "reports/visits/visit-{$visit->id}.pdf";
        PrivateFiles::disk()->put($path, $this->render($visit));

        return $path;
    }
}
