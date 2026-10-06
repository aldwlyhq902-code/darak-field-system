<?php

namespace App\Services;

use App\Contracts\InvoiceProvider;
use App\Models\ExternalDocument;
use App\Models\StockMove;
use App\Models\User;
use App\Models\Visit;
use App\Services\Invoicing\ManualInvoiceProvider;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
        private readonly NotificationService $notifications,
    ) {}

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
        $amount = Money::sum(array_column($lines, 'total'));
        $vatRate = $visit->workOrder?->contract?->vat_rate ?? '0.15';
        $vat = Money::vat($amount, $vatRate);

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

            $this->applyProviderResponse($document, $response);
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

        $key = $idempotencyKey ?? ('cn:'.$returnMove->idempotency_key);
        $part = $returnMove->part;

        // Lock the invoice while reading prior credits and creating the next one.
        // Without this, two simultaneous returns both observed the same VAT
        // remainder and could over-credit it by a halala. The provider call stays
        // outside the transaction so a slow external API never holds this lock.
        [$creditNote, $invoice, $created] = DB::transaction(function () use ($visit, $returnMove, $key, $part): array {
            $existing = ExternalDocument::where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return [$existing, null, false];
            }

            $invoice = ExternalDocument::where('visit_id', $visit->id)
                ->where('doc_type', ExternalDocument::TYPE_INVOICE)
                ->where('status', 'issued')
                ->latest('issued_at')
                ->lockForUpdate()
                ->first();

            if ($invoice === null) {
                throw new RuntimeException('No issued invoice found for this visit.');
            }

            $covered = $invoice->request_payload['covered_move_ids'] ?? [];
            if (in_array($returnMove->id, $covered, true)) {
                throw new RuntimeException(
                    'This return was already deducted from the invoice — no credit note is due.'
                );
            }

            $unitPrice = $this->invoicedUnitPrice($invoice, $part->sku) ?? Money::of($part->sale_price);
            $amount = Money::mul($unitPrice, $returnMove->qty);
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

            return [$creditNote, $invoice, true];
        });

        if (! $created) {
            return $creditNote;
        }

        try {
            $response = $this->provider->createCreditNote(
                (string) $invoice->external_id,
                ['amount' => Money::of($creditNote->amount), 'vat_amount' => Money::of($creditNote->vat_amount)],
                $key,
            );

            $this->applyProviderResponse($creditNote, $response);
        } catch (\Throwable $e) {
            $creditNote->forceFill(['status' => 'failed', 'last_error' => $e->getMessage()])->save();
        }

        $this->audit->record('invoice.credit_note', $creditNote, null, [
            'parent_document_id' => $invoice->id,
            'amount' => Money::of($creditNote->amount),
        ]);

        return $creditNote->refresh();
    }

    /**
     * Manual invoicing: record the tax document the accountant issued outside
     * Darak against its pending request. After this the document is issued and
     * as immutable as any provider-issued one — a later correction is a credit
     * note, never an edit, so a second recording is refused.
     *
     * @param  array{external_number: string, issued_on: string, qr_reference?: ?string}  $data
     */
    public function recordManualIssue(ExternalDocument $document, array $data, User $actor): ExternalDocument
    {
        return DB::transaction(function () use ($document, $data, $actor): ExternalDocument {
            $document = ExternalDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($document->provider !== 'manual' || $document->status !== ManualInvoiceProvider::STATUS_PENDING) {
                throw new RuntimeException('هذا المستند ليس بانتظار تسجيل يدوي. المستند الصادر لا يُعدّل؛ التصحيح يكون بإشعار دائن.');
            }

            $number = trim($data['external_number']);
            $duplicate = ExternalDocument::query()
                ->where('provider', 'manual')
                ->where('doc_type', $document->doc_type)
                ->where('external_number', $number)
                ->whereKeyNot($document->id)
                ->exists();
            if ($duplicate) {
                throw new RuntimeException('رقم المستند الخارجي مسجل مسبقًا لمستند آخر.');
            }

            $before = ['status' => $document->status, 'external_number' => $document->external_number];
            $qr = trim((string) ($data['qr_reference'] ?? ''));
            $document->forceFill([
                'external_id' => $number,
                'external_number' => $number,
                'status' => 'issued',
                'issued_at' => CarbonImmutable::parse($data['issued_on'])->startOfDay(),
                'response_payload' => array_merge($document->response_payload ?? [], [
                    'manual' => [
                        'qr_reference' => $qr !== '' ? $qr : null,
                        'recorded_by' => $actor->id,
                        'recorded_at' => CarbonImmutable::now()->toIso8601String(),
                    ],
                ]),
            ])->save();

            $this->audit->record('invoice.manual_recorded', $document, $before, [
                'status' => 'issued',
                'external_number' => $number,
                'issued_on' => $document->issued_at?->toDateString(),
                'qr_reference' => $qr !== '' ? $qr : null,
            ], $actor->id);

            return $document;
        });
    }

    /** @return array<int, array{sku:string, description:string, qty:float, unit_price:string, total:string}> */
    private function lines(Visit $visit): array
    {
        $lines = [];

        foreach ($this->inventory->visitConsumption($visit) as $row) {
            $lines[] = [
                // The sku is carried so a later credit note can find the price
                // this invoice actually charged.
                'sku' => $row['sku'],
                'description' => $row['name'].' ('.$row['sku'].')',
                'qty' => $row['qty'],
                'unit_price' => Money::of($row['unit_price']),
                'total' => Money::mul($row['qty'], $row['unit_price']),
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
    private function proportionalVat(ExternalDocument $invoice, string $amount): string
    {
        $invoiceAmount = Money::of($invoice->amount);

        if (! Money::isPositive($invoiceAmount)) {
            return Money::zero();
        }

        $alreadyCredited = Money::of($invoice->creditNotes()->sum('amount'));
        $alreadyCreditedVat = Money::of($invoice->creditNotes()->sum('vat_amount'));

        if (Money::equals(Money::add($alreadyCredited, $amount), $invoiceAmount)) {
            return Money::sub($invoice->vat_amount, $alreadyCreditedVat);
        }

        return Money::div(Money::mul($invoice->vat_amount, $amount, 6), $invoiceAmount);
    }

    /** The unit price this invoice charged for a SKU, or null if it had no such line. */
    private function invoicedUnitPrice(ExternalDocument $invoice, string $sku): ?string
    {
        foreach ($invoice->request_payload['lines'] ?? [] as $line) {
            if (($line['sku'] ?? null) === $sku) {
                return Money::of($line['unit_price']);
            }
        }

        return null;
    }

    /**
     * Records what the provider answered. Only a document the provider actually
     * issued gets an issue time; a manual one is parked for the finance team
     * with a task in the notifications inbox and gets the accountant's date
     * when it is recorded.
     *
     * @param  array<string, mixed>  $response
     */
    private function applyProviderResponse(ExternalDocument $document, array $response): void
    {
        $status = $response['status'] ?? 'issued';

        $document->forceFill([
            'external_id' => $response['external_id'] ?? null,
            'external_number' => $response['external_number'] ?? null,
            'status' => $status,
            'issued_at' => $status === 'issued' ? CarbonImmutable::now() : null,
            'response_payload' => $response,
        ])->save();

        if ($status === ManualInvoiceProvider::STATUS_PENDING) {
            $this->notifications->manualInvoiceTask($document);
        }
    }
}
