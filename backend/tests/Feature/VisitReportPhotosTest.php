<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\Visit;
use App\Services\ReportImages;
use App\Services\VisitReportBuilder;
use App\Support\PrivateFiles;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * 0.3.0 regression: the visit PDF failed once a visit had several real photos.
 * Every photo was inlined at full size as base64, the HTML passed
 * pcre.backtrack_limit (1,000,000) and mPDF threw "The HTML code size is
 * larger than pcre.backtrack_limit". Photos now go to mPDF as cached,
 * report-sized JPEG derivatives beside the HTML.
 */
class VisitReportPhotosTest extends DarakTestCase
{
    private const PHOTOS = 8;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(PrivateFiles::diskName());
    }

    public function test_a_visit_with_eight_full_size_photos_renders_within_the_default_memory_limit(): void
    {
        $sources = $this->completedVisitWithPhotos(self::PHOTOS, 4000, 3000);
        $originalBytes = array_sum(array_map(fn ($path) => PrivateFiles::disk()->size($path), $sources));

        // The old template would have inlined all of this as base64.
        $this->assertGreaterThan(5 * (int) ini_get('pcre.backtrack_limit'), $originalBytes * 4 / 3);

        $limitBefore = ini_get('pcre.backtrack_limit');
        gc_collect_cycles();
        $baseline = memory_get_usage();
        memory_reset_peak_usage();

        $pdf = app(VisitReportBuilder::class)->render($this->visit->refresh());

        $extra = memory_get_peak_usage() - $baseline;

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(self::PHOTOS + 1, substr_count($pdf, '/Subtype /Image'), 'every photo and the signature are in the PDF');
        // PHP's default memory_limit is 128M; a worker starts at ~30 MB.
        $this->assertLessThan(96 * 1024 * 1024, $extra, sprintf('render peaked %.1f MB above baseline', $extra / 1048576));
        $this->assertLessThan($originalBytes, strlen($pdf), 'the PDF carries derivatives, not the originals');
        $this->assertSame($limitBefore, ini_get('pcre.backtrack_limit'), 'no global limit change');
    }

    public function test_derivatives_are_cached_on_the_files_disk_and_reused(): void
    {
        $sources = $this->completedVisitWithPhotos(2, 4000, 3000);
        $disk = PrivateFiles::disk();

        app(VisitReportBuilder::class)->render($this->visit->refresh());

        foreach ($sources as $source) {
            $cached = ReportImages::cachePathFor($source);
            $this->assertTrue($disk->exists($cached), "derivative of {$source}");

            [$width, $height, $type] = getimagesizefromstring($disk->get($cached));
            $this->assertSame([ReportImages::MAX_SIDE, 1050, IMAGETYPE_JPEG], [$width, $height, $type]);

            // A later render uses the cache, not the original.
            $disk->delete($source);
        }

        $pdf = app(VisitReportBuilder::class)->render($this->visit->refresh());
        $this->assertSame(3, substr_count($pdf, '/Subtype /Image'));
    }

    public function test_the_html_carries_references_not_image_data(): void
    {
        $this->completedVisitWithPhotos(1, 1200, 900);

        $html = view('reports.visit', [
            'visit' => $this->visit->refresh()->load(['workOrder.client', 'site.client', 'checklistInstances.asset', 'mediaFiles', 'technician']),
            'parts' => [],
            'photoRefs' => ['var:photo1'],
            'signature' => null,
            'signatureRef' => null,
            'retiredAssets' => collect(),
            'templateVersion' => VisitReportBuilder::TEMPLATE_VERSION,
        ])->render();

        $this->assertStringContainsString('src="var:photo1"', $html);
        $this->assertStringNotContainsString('base64', $html);
    }

    public function test_unreadable_evidence_is_left_out_instead_of_failing_the_report(): void
    {
        $this->completedVisitWithPhotos(1, 800, 600);
        $broken = 'media/originals/broken.jpg';
        PrivateFiles::disk()->put($broken, 'not an image');
        $this->photo($broken);

        $pdf = app(VisitReportBuilder::class)->render($this->visit->refresh());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(2, substr_count($pdf, '/Subtype /Image'), 'the good photo and the signature');
    }

    /** @return list<string> storage paths of the photo originals */
    private function completedVisitWithPhotos(int $count, int $width, int $height): array
    {
        $base = $this->noisyPhoto($width, $height);
        $paths = [];

        for ($i = 0; $i < $count; $i++) {
            // Each photo distinct, so none can be de-duplicated away.
            $image = imagecreatefromstring($base);
            imagefilledrectangle($image, 40 * $i, 40, 40 * $i + 300, 340, imagecolorallocate($image, 30 * $i, 200 - 20 * $i, 90));
            ob_start();
            imagejpeg($image, null, 90);
            $bytes = (string) ob_get_clean();
            imagedestroy($image);

            $path = 'media/originals/'.Str::uuid().'.jpg';
            PrivateFiles::disk()->put($path, $bytes);
            $this->photo($path);
            $paths[] = $path;
        }

        $signature = imagecreatetruecolor(900, 300);
        imagefill($signature, 0, 0, imagecolorallocatealpha($signature, 255, 255, 255, 127));
        imagesavealpha($signature, true);
        imagesetthickness($signature, 4);
        imageline($signature, 40, 250, 860, 60, imagecolorallocate($signature, 10, 10, 10));
        ob_start();
        imagepng($signature);
        PrivateFiles::disk()->put('media/originals/signature.png', (string) ob_get_clean());
        imagedestroy($signature);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'signature',
            'mime' => 'image/png',
            'upload_state' => 'complete',
            'original_path' => 'media/originals/signature.png',
            'original_hash' => str_repeat('b', 64),
            'captured_at' => now(),
        ]);

        $this->visit->forceFill([
            'state' => Visit::STATE_COMPLETED,
            'started_at' => now()->subHours(2),
            'closed_at' => now(),
            'on_site_seconds' => 5400,
        ])->save();

        return $paths;
    }

    private function photo(string $path): void
    {
        MediaFile::create([
            'visit_id' => $this->visit->id,
            'asset_id' => $this->asset->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'mime' => 'image/jpeg',
            'upload_state' => 'complete',
            'original_path' => $path,
            'original_hash' => hash('sha256', $path),
            'captured_at' => now(),
        ]);
    }

    /** A camera-sized JPEG with real entropy (several MB), built from a noise tile. */
    private function noisyPhoto(int $width, int $height): string
    {
        $tile = imagecreatetruecolor(200, 200);
        mt_srand(42);
        for ($y = 0; $y < 200; $y++) {
            for ($x = 0; $x < 200; $x++) {
                imagesetpixel($tile, $x, $y, (mt_rand(0, 255) << 16) | (mt_rand(0, 255) << 8) | mt_rand(0, 255));
            }
        }

        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y += 200) {
            for ($x = 0; $x < $width; $x += 200) {
                imagecopy($image, $tile, $x, $y, 0, 0, 200, 200);
            }
        }
        imagedestroy($tile);

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
