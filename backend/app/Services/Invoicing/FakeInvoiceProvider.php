<?php

namespace App\Services\Invoicing;

use App\Contracts\InvoiceProvider;
use Illuminate\Support\Str;

/**
 * Deterministic stand-in for the real provider (Qoyod / Daftra / Wafeq).
 *
 * It exists so the whole invoice + credit note flow can be tested end to end before
 * a provider contract is signed — and so a provider outage never blocks development.
 */
class FakeInvoiceProvider implements InvoiceProvider
{
    /** @var array<string, array<string, mixed>> */
    private static array $documents = [];

    public function createInvoice(array $payload, string $idempotencyKey): array
    {
        if (isset(self::$documents[$idempotencyKey])) {
            return self::$documents[$idempotencyKey]; // replay-safe like the real thing
        }

        $doc = [
            'external_id' => 'INV-' . Str::upper(Str::random(10)),
            'external_number' => 'D' . str_pad((string) (count(self::$documents) + 1), 5, '0', STR_PAD_LEFT),
            'status' => 'issued',
            'issued_at' => now()->toIso8601String(),
            'amount' => $payload['amount'] ?? 0,
            'vat_amount' => $payload['vat_amount'] ?? 0,
        ];

        self::$documents[$idempotencyKey] = $doc;

        return $doc;
    }

    public function createCreditNote(string $parentExternalId, array $payload, string $idempotencyKey): array
    {
        if (isset(self::$documents[$idempotencyKey])) {
            return self::$documents[$idempotencyKey];
        }

        $doc = [
            'external_id' => 'CN-' . Str::upper(Str::random(10)),
            'external_number' => 'C' . str_pad((string) (count(self::$documents) + 1), 5, '0', STR_PAD_LEFT),
            'parent_external_id' => $parentExternalId,
            'status' => 'issued',
            'issued_at' => now()->toIso8601String(),
            'amount' => $payload['amount'] ?? 0,
            'vat_amount' => $payload['vat_amount'] ?? 0,
        ];

        self::$documents[$idempotencyKey] = $doc;

        return $doc;
    }

    public function getStatus(string $externalId): array
    {
        foreach (self::$documents as $doc) {
            if ($doc['external_id'] === $externalId) {
                return $doc;
            }
        }

        return ['external_id' => $externalId, 'status' => 'unknown'];
    }

    public function name(): string
    {
        return 'fake';
    }

    public static function reset(): void
    {
        self::$documents = [];
    }
}
