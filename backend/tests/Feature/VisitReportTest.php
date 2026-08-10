<?php

namespace Tests\Feature;

use App\Models\ChecklistInstance;
use App\Models\MediaFile;
use App\Models\Visit;
use App\Services\VisitReportBuilder;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Acceptance criterion 7: the Arabic visit report generates from a completed visit
 * with its evidence, and its wording stays inside the "operations management"
 * position — never claiming accreditation or issuing a compliance certificate.
 */
class VisitReportTest extends DarakTestCase
{
    public function test_report_generates_a_valid_pdf(): void
    {
        $this->prepareCompletedVisit();

        $pdf = app(VisitReportBuilder::class)->render($this->visit->refresh());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_report_endpoint_returns_pdf_to_the_assigned_technician(): void
    {
        $this->prepareCompletedVisit();

        $response = $this->actingAs($this->technician)
            ->get("/api/v1/visits/{$this->visit->id}/report.pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_report_is_not_readable_by_another_technician(): void
    {
        $this->prepareCompletedVisit();

        $this->actingAs($this->otherTechnician)
            ->get("/api/v1/visits/{$this->visit->id}/report.pdf")
            ->assertStatus(403);
    }

    /**
     * Positioning guard. Darak coordinates accredited parties; it does not accredit.
     * These phrases were removed from the spec after legal review and must not
     * reappear in a generated document.
     */
    public function test_report_template_avoids_accreditation_claims(): void
    {
        $template = file_get_contents(resource_path('views/reports/visit.blade.php'));

        foreach (['شهادة امتثال', 'شرط للرخصة', 'معتمدون لدى'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $template);
        }

        // And it says what it actually is.
        $this->assertStringContainsString('تقرير حالة فنية وسجل صيانة', $template);
    }

    public function test_csv_export_carries_a_bom_so_excel_reads_arabic(): void
    {
        $this->prepareCompletedVisit();

        $response = $this->actingAs($this->owner)->get('/api/v1/reports/visits.csv');
        $response->assertOk();

        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('visit_id', $content);
    }

    public function test_first_time_fix_reports_strict_and_adjusted_figures(): void
    {
        $this->prepareCompletedVisit();

        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/reports/first-time-fix')
            ->assertOk();

        $this->assertArrayHasKey('strict', $response->json('first_time_fix'));
        $this->assertArrayHasKey('adjusted', $response->json('first_time_fix'));
    }

    private function prepareCompletedVisit(): void
    {
        $instance = ChecklistInstance::create([
            'visit_id' => $this->visit->id,
            'asset_id' => $this->asset->id,
            'status' => 'ok',
            'note' => 'تم غسيل الوحدة وفحص الضغط.',
            'no_parts_used' => true,
            'completed_at' => now(),
        ]);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'checklist_instance_id' => $instance->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'upload_state' => 'complete',
            'original_path' => 'media/test/photo.jpg',
            'original_hash' => str_repeat('a', 64),
            'captured_at' => now(),
        ]);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'signature',
            'upload_state' => 'complete',
            'original_path' => 'media/test/sig.png',
            'original_hash' => str_repeat('b', 64),
            'captured_at' => now(),
        ]);

        $this->visit->forceFill([
            'state' => Visit::STATE_COMPLETED,
            'started_at' => now()->subHours(2),
            'closed_at' => now(),
            'on_site_seconds' => 5400,
        ])->save();
    }
}
