<?php

namespace Tests\Feature;

use App\Models\Subcontractor;
use App\Models\SubcontractorOrder;
use App\Services\SubcontractorService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\DarakTestCase;

/**
 * Hood, gas and pipework run through partners, and the whole phase-1 test is
 * subcontracted. If the partner's cost is not captured, every margin in the
 * system is wrong by exactly that amount.
 */
class SubcontractorTest extends DarakTestCase
{
    private function partner(array $overrides = []): Subcontractor
    {
        return Subcontractor::create(array_merge([
            'name' => 'شريك تنظيف الهود',
            'specialties' => ['hood'],
            'phone' => '0560000000',
            'issues_official_certificates' => true,
            'is_active' => true,
        ], $overrides));
    }

    public function test_margin_is_computed_before_the_order_is_confirmed(): void
    {
        $preview = app(SubcontractorService::class)->preview(600, 1000);

        $this->assertEqualsWithDelta(400.0, $preview['margin'], 0.01);
        $this->assertEqualsWithDelta(40.0, $preview['margin_percent'], 0.1);
        $this->assertNull($preview['warning']);
    }

    public function test_a_thin_margin_is_flagged(): void
    {
        $preview = app(SubcontractorService::class)->preview(900, 1000);

        $this->assertEqualsWithDelta(10.0, $preview['margin_percent'], 0.1);
        $this->assertStringContainsString('أقل من 20%', $preview['warning']);
    }

    public function test_a_loss_making_order_is_named_as_such(): void
    {
        $preview = app(SubcontractorService::class)->preview(1200, 1000);

        $this->assertLessThan(0, $preview['margin']);
        $this->assertStringContainsString('يخسر', $preview['warning']);
    }

    public function test_assigning_records_the_partner_cost_against_the_work_order(): void
    {
        $order = app(SubcontractorService::class)->assign(
            $this->partner(),
            $this->visit->workOrder,
            600,
            1000,
            $this->visit,
        );

        $this->assertSame('assigned', $order->status);
        $this->assertEqualsWithDelta(400.0, $order->margin(), 0.01);
        $this->assertSame($this->visit->id, $order->visit_id);
        $this->assertStringStartsWith('SUB-', $order->order_no);
    }

    public function test_an_inactive_partner_cannot_be_assigned(): void
    {
        $this->expectExceptionMessage('غير نشط');

        app(SubcontractorService::class)->assign(
            $this->partner(['is_active' => false]),
            $this->visit->workOrder,
            600,
            1000,
        );
    }

    public function test_documents_are_stored_under_the_issuing_party_name(): void
    {
        $service = app(SubcontractorService::class);
        $order = $service->assign($this->partner(), $this->visit->workOrder, 600, 1000, $this->visit);

        $service->attachDocument($order, 'docs/cert.pdf', 'شهادة تنظيف هود', 'شريك تنظيف الهود', '2026-08-10');

        $document = $order->refresh()->documents[0];

        // Darak coordinates accredited parties; filing a certificate as if Darak
        // issued it would be a real misrepresentation, not a formality.
        $this->assertSame('شريك تنظيف الهود', $document['issued_by']);
        $this->assertSame('شهادة تنظيف هود', $document['title']);
    }

    public function test_cancelled_orders_carry_no_cost(): void
    {
        $service = app(SubcontractorService::class);

        $kept = $service->assign($this->partner(), $this->visit->workOrder, 600, 1000, $this->visit);
        $cancelled = $service->assign($this->partner(), $this->visit->workOrder, 400, 700, $this->visit);

        $service->cancel($cancelled, 'العميل ألغى');

        $this->assertEqualsWithDelta(600.0, $service->costForVisit($this->visit), 0.01);
        $this->assertEqualsWithDelta(1000.0, $service->revenueForVisit($this->visit), 0.01);
        $this->assertSame('cancelled', $cancelled->refresh()->status);
        $this->assertNotNull($kept->refresh());
    }

    public function test_panel_refuses_a_negative_margin_without_explicit_confirmation(): void
    {
        $partner = $this->partner();

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.sub.assign'), [
                'subcontractor_id' => $partner->id,
                'work_order_id' => $this->visit->work_order_id,
                'purchase_cost' => 1200,
                'sale_price' => 1000,
            ])
            ->assertSessionHas('err', fn ($m) => str_contains($m, 'يخسر'));

        $this->assertSame(0, SubcontractorOrder::count());
    }

    public function test_panel_allows_a_negative_margin_when_deliberately_confirmed(): void
    {
        $partner = $this->partner();

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.sub.assign'), [
                'subcontractor_id' => $partner->id,
                'work_order_id' => $this->visit->work_order_id,
                'purchase_cost' => 1200,
                'sale_price' => 1000,
                'confirm_negative_margin' => '1',
            ])
            ->assertSessionHas('ok');

        $this->assertSame(1, SubcontractorOrder::count());
    }

    public function test_panel_uploads_a_partner_document(): void
    {
        Storage::fake('local');

        $order = app(SubcontractorService::class)
            ->assign($this->partner(), $this->visit->workOrder, 600, 1000, $this->visit);

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.sub.doc.upload', $order), [
                'file' => UploadedFile::fake()->create('cert.pdf', 40, 'application/pdf'),
                'title' => 'شهادة الدفاع المدني',
                'issued_by' => 'شركة سلامة معتمدة',
            ])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'شركة سلامة معتمدة'));

        $this->assertCount(1, $order->refresh()->documents);
    }

    public function test_subcontractor_page_renders(): void
    {
        $this->partner();

        $this->actingAs($this->owner, 'web')
            ->get(route('panel.sub'))
            ->assertOk()
            ->assertSee('المقاولون من الباطن');
    }
}
