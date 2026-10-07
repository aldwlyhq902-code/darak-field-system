<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\ChecklistInstance;
use App\Models\MediaFile;
use App\Models\Visit;
use App\Services\InventoryService;
use App\Services\VisitReportBuilder;
use App\Support\PrivateFiles;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * 0.3.0 regression: a typical visit report came out as three pages, the third
 * holding nothing but the template-version footer line, which sat in the
 * content flow after the closing note and spilled over on its own.
 */
class VisitReportPagesTest extends DarakTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(PrivateFiles::diskName());
    }

    public function test_a_typical_visit_has_no_trailing_footer_only_page(): void
    {
        // As in the showcase: three assets with notes, two parts, five photos, a signature.
        $this->asset->forceFill(['type' => 'split_ac', 'name' => 'مكيف سبليت الصالة'])->save();
        $assets = [$this->asset];
        foreach ([['split_ac', 'سبليت المطبخ'], ['chiller', 'ثلاجة العرض']] as [$type, $name]) {
            $assets[] = Asset::create(['site_id' => $this->site->id, 'name' => $name, 'type' => $type]);
        }
        foreach ($assets as $i => $asset) {
            ChecklistInstance::create([
                'visit_id' => $this->visit->id, 'asset_id' => $asset->id, 'status' => $i === 2 ? 'needs_followup' : 'ok',
                'note' => 'تنظيف الفلاتر وفحص الضغط ودرجة الحرارة بعد التشغيل، والقراءات ضمن المعدل الطبيعي.',
                'no_parts_used' => false, 'completed_at' => now(),
            ]);
        }
        $inventory = app(InventoryService::class);
        $inventory->receipt((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id);
        $inventory->loadVehicle((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id, $this->vehicleStock->id);
        $inventory->issueToVisit((string) Str::uuid(), $this->part->id, 1, $this->vehicleStock->id, $this->visit);

        foreach (range(1, 5) as $i) {
            $this->media("media/originals/photo-{$i}.jpg", 'photo_after', $this->jpeg(1400, 1050, $i));
        }
        $this->media('media/originals/signature.png', 'signature', $this->png());
        $this->visit->forceFill(['state' => Visit::STATE_COMPLETED, 'started_at' => now()->subHours(2), 'closed_at' => now(), 'on_site_seconds' => 5400])->save();

        $pdf = app(VisitReportBuilder::class)->render($this->visit->refresh());

        $this->assertSame(2, $this->pageCount($pdf), 'assets and parts, then photos and signature — no third page');
    }

    public function test_the_version_footer_is_a_page_footer_not_trailing_content(): void
    {
        $template = file_get_contents(resource_path('views/reports/visit.blade.php'));

        $this->assertStringContainsString('<htmlpagefooter name="report-footer">', $template);
        $this->assertStringContainsString('<sethtmlpagefooter name="report-footer" value="on"', $template);
        // Nothing but closing tags may follow the closing note.
        $afterNote = substr($template, strrpos($template, '</div>', strpos($template, 'class="note"')) + 6);
        $this->assertStringNotContainsString('class="foot"', $afterNote);
    }

    public function test_a_short_visit_is_a_single_page(): void
    {
        ChecklistInstance::create(['visit_id' => $this->visit->id, 'asset_id' => $this->asset->id, 'status' => 'ok', 'no_parts_used' => true, 'completed_at' => now()]);
        $this->visit->forceFill(['state' => Visit::STATE_COMPLETED, 'closed_at' => now()])->save();

        $this->assertSame(1, $this->pageCount(app(VisitReportBuilder::class)->render($this->visit->refresh())));
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page(?![s\w])#', $pdf);
    }

    private function media(string $path, string $kind, string $bytes): void
    {
        PrivateFiles::disk()->put($path, $bytes);
        MediaFile::create([
            'visit_id' => $this->visit->id, 'asset_id' => $kind === 'signature' ? null : $this->asset->id,
            'client_media_id' => (string) Str::uuid(), 'kind' => $kind,
            'mime' => $kind === 'signature' ? 'image/png' : 'image/jpeg', 'upload_state' => 'complete',
            'original_path' => $path, 'original_hash' => hash('sha256', $bytes), 'captured_at' => now(),
        ]);
    }

    private function jpeg(int $width, int $height, int $seed): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 40 * $seed % 255, 120, 160));
        ob_start();
        imagejpeg($image, null, 80);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(900, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 40, 250, 860, 60, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
