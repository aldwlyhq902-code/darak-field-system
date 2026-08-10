<?php

namespace App\Services;

use App\Contracts\InvoiceProvider;
use App\Models\ExternalDocument;
use App\Models\StockMove;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Critical correction from review: an ISSUED e-invoice is immutable. Earlier drafts
 * said a part return should "correct the invoice" — that is not legally possible and
 * would have been built as a silent edit.
 *
 * The correct flow: the return corrects stock and cost locally, then requests a
 * CREDIT NOTE from the provider that references the original document. The original
 * is never touched.
 */
class InvoiceService
{
    public function __construct(
        private readonly InvoiceProvider $provider,
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Issue an invoice for out-of-contract work on a visit. */
    public function invoiceVisit(Visit $visit, ?string $idempotencyKey = null): ExternalDocument
    {
        $visit->loadMissing(['workOrder.client', 'workOrder.contract', 'stockMoves.part']);

        // Derived from the visit, not a fresh UUID: a retried request must not
        // issue a second invoice for the same work.
        $key = $idempotencyKey ?? "invoice:visit:{$visit->id}";

        $existing = ExternalDocument::where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        $lines = $this->lines($visit);
        $amount = round(array_sum(array_column($lines, 'total')), 2);
        $vatRate = (float) ($visit->workOrder?->contract?->vat_rate ?? 0.15);
        $vat = round($amount * $vatRate, 2);

        // Exactly which movements this invoice was computed from. A later credit
        // note consults this instead of comparing timestamps — a return and an
        // invoice raised in the same second are indistinguishable by clock, and
        // getting it wrong refunds goods that were never charged.
        $coveredMoveIds = $visit->stockMoves->pluck('id')->all();

        $document = ExternalDocument::create([
            'doc_type' => ExternalDocument::TYPE_INVOICE,
            'provider' => $this->provider->name(),
            'client_id' => $visit->workOrder?->client_id,
            'contract_id' => $visit->workOrder?->contract_id,
            'work_order_id' => $visit->work_order_id,
            'visit_id' => $visit->id,
            'amount' => $amount,
            'vat_amount' => $vat,
            'status' => 'requested',
            'idempotency_key' => $key,
            'request_payload' => [
                'lines' => $lines,
                'amount' => $amount,
                'vat_amount' => $vat,
                'covered_move_ids' => $coveredMoveIds,
            ],
        ]);

        try {
            $response = $this->provider->createInvoice(
                ['lines' => $lines, 'amount' => $amount, 'vat_amount' => $vat],
                $key,
            );

            $document->forceFill([
                'external_id' => $response['external_id'] ?? null,
                'external_number' => $response['external_number'] ?? null,
                'status' => $response['status'] ?? 'issued',
                'issued_at' => CarbonImmutable::now(),
                'response_payload' => $response,
            ])->save();
        } catch (\Throwable $e) {
            // The provider being down must not lose the request — it stays queued
            // as 'failed' with the error visible, never silently dropped.
            $document->forceFill(['status' => 'failed', 'last_error' => $e->getMessage()])->save();
        }

        $this->audit->record('invoice.requested', $document, null, $document->only(['doc_type', 'amount', 'status']));

        return $document->refresh();
    }

    /**
     * Part returned after the invoice was issued.
     * Corrects stock and cost, then requests a linked credit note.
     */
    public function creditNoteForReturn(StockMove $returnMove, ?string $idempotencyKey = null): ExternalDocument
    {
        if ($returnMove->move_type !== StockMove::VISIT_RETURN) {
            throw new RuntimeException('A credit note can only follow a VISIT_RETURN movement.');
        }

        $visit = $returnMove->visit;

        if ($visit === null) {
            throw new RuntimeException('Return movement is not linked to a visit.');
        }

        $invoice = ExternalDocument::where('visit_id', $visit->id)
            ->where('doc_type', ExternalDocument::TYPE_INVOICE)
            ->where('status', 'issued')
            ->latest('issued_at')
            ->first();

        if ($invoice === null) {
            throw new RuntimeException('No issued invoice found for this visit.');
        }

        // A return the invoice already accounted for was netted out of its amount:
        // the client was billed the NET quantity. Crediting it again refunds goods
        // that were never charged — and since the mobile client is built to retry
        // idempotently, this path is reached in ordinary operation, not by abuse.
        $covered = $invoice->request_payload['covered_move_ids'] ?? [];

        if (in_array($returnMove->id, $covered, true)) {
            throw new RuntimeException(
                'This return was already deducted from the invoice — no credit note is due.'
            );
        }

        $key = $idempotencyKey ?? ('cn:' . $returnMove->idempotency_key);

        $existing = ExternalDocument::where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        $part = $returnMove->part;

        // The price the invoice actually charged, not today's list price. A
        // catalogue edit between invoicing and return must not change the refund.
        $unitPrice = $this->invoicedUnitPrice($invoice, $part->sku) ?? (float) $part->sale_price;
        $amount = round($unitPrice * (float) $returnMove->qty, 2);

        // VAT as a proportional share of the INVOICE's stored figure, with the
        // final credit taking whatever remains. Recomputing it independently
        // drifts a cent or two per note, so a fully returned invoice never quite
        // reverses to zero.
        $vat = $this->proportionalVat($invoice, $amount);

        $creditNote = ExternalDocument::create([
            'doc_type' => ExternalDocument::TYPE_CREDIT_NOTE,
            'provider' => $this->provider->name(),
            'parent_document_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'contract_id' => $invoice->contract_id,
            'work_order_id' => $invoice->work_order_id,
            'visit_id' => $visit->id,
            'amount' => $amount,
            'vat_amount' => $vat,
            'status' => 'requested',
            'idempotency_key' => $key,
            'request_payload' => [
                'reason' => 'part_returned',
                'part_sku' => $part->sku,
                'qty' => (float) $returnMove->qty,
                'unit_price' => $unitPrice,
                'stock_move_id' => $returnMove->id,
            ],
        ]);

        try {
            $response = $this->provider->createCreditNote(
                (string) $invoice->external_id,
                ['amount' => $amount, 'vat_amount' => $vat],
                $key,
            );

            $creditNote->forceFill([
                'external_id' => $response['external_id'] ?? null,
                'external_number' => $response['external_number'] ?? null,
                'status' => $response['status'] ?? 'issued',
                'issued_at' => CarbonImmutable::now(),
                'response_payload' => $response,
            ])->save();
        } catch (\Throwable $e) {
            $creditNote->forceFill(['status' => 'failed', 'last_error' => $e->getMessage()])->save();
        }

        $this->audit->record('invoice.credit_note', $creditNote, null, [
            'parent_document_id' => $invoice->id,
            'amount' => $amount,
        ]);

        return $creditNote->refresh();
    }

    /** @return array<int, array{sku:string, description:string, qty:float, unit_price:float, total:float}> */
    private function lines(Visit $visit): array
    {
        $lines = [];

        foreach ($this->inventory->visitConsumption($visit) as $row) {
            $lines[] = [
                // The sku is carried so a later credit note can find the price
                // this invoice actually charged.
                'sku' => $row['sku'],
                'description' => $row['name'] . ' (' . $row['sku'] . ')',
                'qty' => $row['qty'],
                'unit_price' => $row['unit_price'],
                'total' => round($row['qty'] * $row['unit_price'], 2),
            ];
        }

        return $lines;
    }

    /**
     * The VAT share for a partial credit against an invoice.
     *
     * When the credit closes out the invoice completely, it takes the exact
     * remainder so the notes sum to the invoiced VAT to the halala.
     */
    private function proportionalVat(ExternalDocument $invoice, float $amount): float
    {
        $invoiceAmount = (float) $invoice->amount;

        if ($invoiceAmount <= 0) {
            return 0.0;
        }

        $alreadyCredited = (float) $invoice->creditNotes()->sum('amount');
        $alreadyCreditedVat = (float) $invoice->creditNotes()->sum('vat_amount');

        $isFinal = abs(($alreadyCredited + $amount) - $invoiceAmount) < 0.005;

        if ($isFinal) {
            return round((float) $invoice->vat_amount - $alreadyCreditedVat, 2);
        }

        return round((float) $invoice->vat_amount * ($amount / $invoiceAmount), 2);
    }

    /** The unit price this invoice charged for a SKU, or null if it had no such line. */
    private function invoicedUnitPrice(ExternalDocument $invoice, string $sku): ?float
    {
        foreach ($invoice->request_payload['lines'] ?? [] as $line) {
            if (($line['sku'] ?? null) === $sku) {
                return (float) $line['unit_price'];
            }
        }

        return null;
    }
}
