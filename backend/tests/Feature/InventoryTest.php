<?php

namespace Tests\Feature;

use App\Models\ExternalDocument;
use App\Models\StockMove;
use App\Models\Visit;
use App\Services\InvoiceService;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Acceptance criteria 4 and 5: stock arithmetic, and the rule that an issued
 * invoice is never edited — a return produces a linked credit note.
 */
class InventoryTest extends DarakTestCase
{
    public function test_load_ten_issue_two_return_one_leaves_nine(): void
    {
        $inv = $this->inventory();

        $inv->receipt((string) Str::uuid(), $this->part->id, 50, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id, $this->vehicleStock->id);

        $this->assertEqualsWithDelta(10.0, $inv->balance($this->part->id, $this->vehicleStock->id), 0.001);

        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->visit);
        $this->assertEqualsWithDelta(8.0, $inv->balance($this->part->id, $this->vehicleStock->id), 0.001);

        $inv->returnFromVisit((string) Str::uuid(), $issue, 1, $this->vehicleStock->id);

        $this->assertEqualsWithDelta(9.0, $inv->balance($this->part->id, $this->vehicleStock->id), 0.001);
        $this->assertSame(3, StockMove::where('part_id', $this->part->id)->whereNotNull('visit_id')->count() + 1);
    }

    public function test_stock_cannot_go_negative(): void
    {
        $inv = $this->inventory();
        $inv->receipt((string) Str::uuid(), $this->part->id, 3, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 3, $this->warehouse->id, $this->vehicleStock->id);

        $this->expectExceptionMessage('Insufficient stock');
        $inv->issueToVisit((string) Str::uuid(), $this->part->id, 4, $this->vehicleStock->id, $this->visit);
    }

    public function test_visit_consumption_is_net_of_returns(): void
    {
        $inv = $this->inventory();
        $inv->receipt((string) Str::uuid(), $this->part->id, 20, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id, $this->vehicleStock->id);

        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 3, $this->vehicleStock->id, $this->visit);
        $inv->returnFromVisit((string) Str::uuid(), $issue, 1, $this->vehicleStock->id);

        $consumption = $inv->visitConsumption($this->visit->refresh());

        $this->assertCount(1, $consumption);
        $this->assertEqualsWithDelta(2.0, $consumption[0]['qty'], 0.001);
    }

    public function test_return_after_invoicing_creates_a_credit_note_and_leaves_the_invoice_untouched(): void
    {
        $inv = $this->inventory();
        $invoices = app(InvoiceService::class);

        $inv->receipt((string) Str::uuid(), $this->part->id, 20, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id, $this->vehicleStock->id);
        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->visit);

        $invoice = $invoices->invoiceVisit($this->visit->refresh());

        $this->assertSame('issued', $invoice->status);
        $this->assertEqualsWithDelta(190.0, (float) $invoice->amount, 0.01);   // 2 x 95 pre-VAT
        $this->assertEqualsWithDelta(28.5, (float) $invoice->vat_amount, 0.01); // 15%

        $snapshot = $this->documentSnapshot($invoice);

        $return = $inv->returnFromVisit((string) Str::uuid(), $issue, 1, $this->vehicleStock->id);
        $creditNote = $invoices->creditNoteForReturn($return);

        $this->assertSame(ExternalDocument::TYPE_CREDIT_NOTE, $creditNote->doc_type);
        $this->assertSame($invoice->id, $creditNote->parent_document_id);
        $this->assertEqualsWithDelta(95.0, (float) $creditNote->amount, 0.01);

        // The original document is unchanged — an issued e-invoice is immutable.
        $this->assertSame($snapshot, $this->documentSnapshot($invoice->refresh()));
    }

    public function test_credit_note_request_is_replay_safe(): void
    {
        $inv = $this->inventory();
        $invoices = app(InvoiceService::class);

        $inv->receipt((string) Str::uuid(), $this->part->id, 20, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id, $this->vehicleStock->id);
        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->visit);
        $invoices->invoiceVisit($this->visit->refresh());

        $return = $inv->returnFromVisit((string) Str::uuid(), $issue, 1, $this->vehicleStock->id);

        $first = $invoices->creditNoteForReturn($return);
        $second = $invoices->creditNoteForReturn($return);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ExternalDocument::where('doc_type', ExternalDocument::TYPE_CREDIT_NOTE)->count());
    }

    /** Scalar snapshot — comparing Carbon objects would test identity, not value. */
    private function documentSnapshot(ExternalDocument $doc): array
    {
        return [
            'external_id' => $doc->external_id,
            'external_number' => $doc->external_number,
            'amount' => (string) $doc->amount,
            'vat_amount' => (string) $doc->vat_amount,
            'status' => $doc->status,
            'issued_at' => $doc->issued_at?->toIso8601String(),
        ];
    }

    public function test_a_return_that_predates_the_invoice_is_not_credited_twice(): void
    {
        $inv = $this->inventory();
        $invoices = app(InvoiceService::class);

        $inv->receipt((string) Str::uuid(), $this->part->id, 20, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id, $this->vehicleStock->id);
        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 3, $this->vehicleStock->id, $this->visit);

        // The return happens first, so the invoice charges the NET two units.
        $return = $inv->returnFromVisit((string) Str::uuid(), $issue, 1, $this->vehicleStock->id);
        $invoice = $invoices->invoiceVisit($this->visit->refresh());

        $this->assertEqualsWithDelta(190.0, (float) $invoice->amount, 0.01, '2 units were charged');

        // The client retries the return request — which it is designed to do.
        $this->expectExceptionMessage('already deducted from the invoice');
        $invoices->creditNoteForReturn($return);
    }

    public function test_a_credit_note_uses_the_price_the_invoice_charged_not_todays_price(): void
    {
        $inv = $this->inventory();
        $invoices = app(InvoiceService::class);

        $inv->receipt((string) Str::uuid(), $this->part->id, 20, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id, $this->vehicleStock->id);
        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->visit);

        $invoices->invoiceVisit($this->visit->refresh());

        // The catalogue is repriced after invoicing.
        $this->part->forceFill(['sale_price' => 250])->save();

        $return = $inv->returnFromVisit((string) Str::uuid(), $issue, 1, $this->vehicleStock->id);
        $creditNote = $invoices->creditNoteForReturn($return);

        $this->assertEqualsWithDelta(
            95.0,
            (float) $creditNote->amount,
            0.01,
            'the refund follows the invoiced price, not the new list price',
        );
    }

    public function test_invoicing_the_same_visit_twice_produces_one_invoice(): void
    {
        $inv = $this->inventory();
        $invoices = app(InvoiceService::class);

        $inv->receipt((string) Str::uuid(), $this->part->id, 20, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id, $this->vehicleStock->id);
        $inv->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->visit);

        $first = $invoices->invoiceVisit($this->visit->refresh());
        $second = $invoices->invoiceVisit($this->visit->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ExternalDocument::where('doc_type', ExternalDocument::TYPE_INVOICE)->count());
    }

    public function test_return_without_an_issued_invoice_is_refused_clearly(): void
    {
        $inv = $this->inventory();
        $inv->receipt((string) Str::uuid(), $this->part->id, 20, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id, $this->vehicleStock->id);
        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->visit);
        $return = $inv->returnFromVisit((string) Str::uuid(), $issue, 1, $this->vehicleStock->id);

        $this->expectExceptionMessage('No issued invoice');
        app(InvoiceService::class)->creditNoteForReturn($return);
    }
}
