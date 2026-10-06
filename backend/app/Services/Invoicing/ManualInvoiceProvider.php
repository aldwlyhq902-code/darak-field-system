<?php

namespace App\Services\Invoicing;

use App\Contracts\InvoiceProvider;

/**
 * Invoices are issued OUTSIDE Darak — typically by the accountant in a
 * ZATCA-compliant tool — until an e-invoicing integration is contracted.
 *
 * Nothing is sent anywhere. Each request is parked as `pending_manual`; an
 * authorised finance user then records the external number, issue date and QR
 * reference against the document (InvoiceService::recordManualIssue). Darak
 * never invents a tax number, so the record is honest about what exists.
 */
class ManualInvoiceProvider implements InvoiceProvider
{
    public const STATUS_PENDING = 'pending_manual';

    public function createInvoice(array $payload, string $idempotencyKey): array
    {
        return $this->pending($payload, $idempotencyKey);
    }

    public function createCreditNote(string $parentExternalId, array $payload, string $idempotencyKey): array
    {
        return $this->pending($payload, $idempotencyKey) + ['parent_external_id' => $parentExternalId];
    }

    public function getStatus(string $externalId): array
    {
        // The external tool is the source of truth; Darak cannot poll it.
        return ['external_id' => $externalId, 'status' => 'unknown'];
    }

    public function name(): string
    {
        return 'manual';
    }

    /** @param array<string, mixed> $payload */
    private function pending(array $payload, string $idempotencyKey): array
    {
        return [
            'external_id' => null,
            'external_number' => null,
            'status' => self::STATUS_PENDING,
            'amount' => $payload['amount'] ?? 0,
            'vat_amount' => $payload['vat_amount'] ?? 0,
            'idempotency_key' => $idempotencyKey,
        ];
    }
}
