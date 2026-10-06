<?php

namespace App\Services\Invoicing;

use App\Contracts\InvoiceProvider;
use InvalidArgumentException;

/**
 * The one place a DARAK_INVOICE_PROVIDER value becomes a provider.
 *
 * Every accepted value has an explicit arm. An unknown or misspelled value is a
 * configuration error and fails loudly at resolution — it used to fall through
 * to the fake, which would have "issued" invoices that never reached ZATCA.
 */
final class InvoiceProviderFactory
{
    /** @var array<string, class-string<InvoiceProvider>> */
    public const PROVIDERS = [
        'fake' => FakeInvoiceProvider::class,
        'manual' => ManualInvoiceProvider::class,
    ];

    /**
     * Providers whose documents are legally valid tax documents. The fake only
     * simulates numbering and must never be used where real clients are billed.
     */
    private const PRODUCTION_SAFE = ['manual'];

    public static function make(?string $name): InvoiceProvider
    {
        $key = self::normalize($name);

        return match ($key) {
            'fake' => new FakeInvoiceProvider,
            'manual' => new ManualInvoiceProvider,
            default => throw new InvalidArgumentException(sprintf(
                'Unknown DARAK_INVOICE_PROVIDER [%s]. Supported values: %s.',
                (string) $name,
                implode(', ', array_keys(self::PROVIDERS)),
            )),
        };
    }

    public static function isKnown(?string $name): bool
    {
        return array_key_exists(self::normalize($name), self::PROVIDERS);
    }

    /** For production preflight: false for the fake and for anything unknown. */
    public static function isProductionSafe(?string $name): bool
    {
        return in_array(self::normalize($name), self::PRODUCTION_SAFE, true);
    }

    private static function normalize(?string $name): string
    {
        return strtolower(trim((string) $name));
    }
}
