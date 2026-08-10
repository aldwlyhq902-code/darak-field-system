<?php

namespace App\Contracts;

/**
 * E-invoicing is BOUGHT, not built (binding architectural decision). Darak keeps a
 * reference and a status; the provider owns the document, the ZATCA clearance and
 * the legal numbering.
 *
 * Everything goes through this interface so the provider can be swapped without
 * touching the rest of the system, and so tests run against a fake.
 */
interface InvoiceProvider
{
    /** @param array<string, mixed> $payload */
    public function createInvoice(array $payload, string $idempotencyKey): array;

    /**
     * An issued e-invoice cannot be edited or deleted. A correction is a separate
     * credit note that references the original document.
     *
     * @param array<string, mixed> $payload
     */
    public function createCreditNote(string $parentExternalId, array $payload, string $idempotencyKey): array;

    public function getStatus(string $externalId): array;

    public function name(): string;
}
