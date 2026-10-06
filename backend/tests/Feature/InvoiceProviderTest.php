<?php

namespace Tests\Feature;

use App\Contracts\InvoiceProvider;
use App\Models\Client;
use App\Models\ExternalDocument;
use App\Models\NotificationMessage;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Invoicing\FakeInvoiceProvider;
use App\Services\Invoicing\InvoiceProviderFactory;
use App\Services\Invoicing\ManualInvoiceProvider;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\DarakTestCase;

class InvoiceProviderTest extends DarakTestCase
{
    public function test_unknown_provider_fails_on_resolution_instead_of_becoming_the_fake(): void
    {
        config(['darak.invoice_provider' => 'zatca-typo']);
        $this->app->forgetInstance(InvoiceProvider::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown DARAK_INVOICE_PROVIDER [zatca-typo]');
        $this->app->make(InvoiceProvider::class);
    }

    public function test_each_supported_provider_has_an_explicit_arm_and_a_production_verdict(): void
    {
        $this->assertInstanceOf(FakeInvoiceProvider::class, InvoiceProviderFactory::make('fake'));
        $this->assertInstanceOf(ManualInvoiceProvider::class, InvoiceProviderFactory::make('manual'));
        $this->assertFalse(InvoiceProviderFactory::isProductionSafe('fake'));
        $this->assertTrue(InvoiceProviderFactory::isProductionSafe('manual'));
        $this->assertFalse(InvoiceProviderFactory::isProductionSafe('qoyod'));
        $this->assertFalse(InvoiceProviderFactory::isProductionSafe(null));
        $this->assertFalse(InvoiceProviderFactory::isKnown('qoyod'));
    }

    public function test_manual_invoice_is_parked_recorded_once_and_never_edited_afterwards(): void
    {
        $this->useManualProvider();
        $issue = $this->consumeTwoParts();

        $invoice = app(InvoiceService::class)->invoiceVisit($this->visit->refresh());

        $this->assertSame('manual', $invoice->provider);
        $this->assertSame(ManualInvoiceProvider::STATUS_PENDING, $invoice->status);
        $this->assertNull($invoice->issued_at, 'nothing was issued yet');
        $this->assertNull($invoice->external_number);
        $this->assertSame('190.00', (string) $invoice->amount);
        $this->assertSame('28.50', (string) $invoice->vat_amount);
        $this->assertSame(1, NotificationMessage::where('type', NotificationMessage::TYPE_MANUAL_INVOICE)->count());

        $finance = $this->financeUser();
        $this->actingAs($finance, 'web')->get(route('panel.invoices'))
            ->assertOk()->assertSee('بانتظار التسجيل اليدوي (1)');

        $this->post(route('panel.invoices.record', $invoice), [
            'external_number' => 'INV-2026-0042',
            'issued_on' => today()->toDateString(),
            'qr_reference' => 'AQ5RcmFkZSBOYW1l',
        ])->assertRedirect()->assertSessionHas('ok');

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('INV-2026-0042', $invoice->external_number);
        $this->assertSame(today()->toDateString(), $invoice->issued_at->toDateString());
        $this->assertSame('AQ5RcmFkZSBOYW1l', $invoice->response_payload['manual']['qr_reference']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.manual_recorded', 'auditable_id' => $invoice->id]);

        // An issued document is immutable: a second recording is refused.
        $this->post(route('panel.invoices.record', $invoice), [
            'external_number' => 'INV-EDITED',
            'issued_on' => today()->toDateString(),
        ])->assertRedirect()->assertSessionHas('err');
        $this->assertSame('INV-2026-0042', $invoice->refresh()->external_number);

        // A return after issue creates a pending-manual credit note + inbox task.
        $return = $this->inventory()->returnFromVisit((string) Str::uuid(), $issue, 1, $this->vehicleStock->id);
        $creditNote = app(InvoiceService::class)->creditNoteForReturn($return);

        $this->assertSame(ExternalDocument::TYPE_CREDIT_NOTE, $creditNote->doc_type);
        $this->assertSame(ManualInvoiceProvider::STATUS_PENDING, $creditNote->status);
        $this->assertSame($invoice->id, $creditNote->parent_document_id);
        $this->assertSame('95.00', (string) $creditNote->amount);
        $this->assertSame(2, NotificationMessage::where('type', NotificationMessage::TYPE_MANUAL_INVOICE)->count());
        $this->assertSame('INV-2026-0042', $invoice->refresh()->external_number, 'the original stays untouched');

        $this->post(route('panel.invoices.record', $creditNote), [
            'external_number' => 'CN-2026-0007',
            'issued_on' => today()->toDateString(),
        ])->assertRedirect()->assertSessionHas('ok');
        $this->assertSame('issued', $creditNote->refresh()->status);
    }

    public function test_manual_recording_is_tenant_scoped_and_needs_finance_permission(): void
    {
        $this->useManualProvider();
        $this->consumeTwoParts();
        $invoice = app(InvoiceService::class)->invoiceVisit($this->visit->refresh());

        $outsider = $this->otherCompanyOwner();
        $this->actingAs($outsider, 'web')->post(route('panel.invoices.record', $invoice), [
            'external_number' => 'HIJACK-1',
            'issued_on' => today()->toDateString(),
        ])->assertNotFound();
        $this->get(route('panel.invoices'))->assertOk()->assertSee('بانتظار التسجيل اليدوي (0)');

        $noFinance = $this->panelUser(['operations'], 'ops@test.local');
        $this->actingAs($noFinance, 'web')->post(route('panel.invoices.record', $invoice), [
            'external_number' => 'NOPE-1',
            'issued_on' => today()->toDateString(),
        ])->assertForbidden();

        $this->assertSame(ManualInvoiceProvider::STATUS_PENDING, $invoice->refresh()->status);
        $this->assertNull($invoice->external_number);
    }

    private function useManualProvider(): void
    {
        config(['darak.invoice_provider' => 'manual']);
        $this->app->forgetInstance(InvoiceProvider::class);
    }

    private function consumeTwoParts()
    {
        $inv = $this->inventory();
        $inv->receipt((string) Str::uuid(), $this->part->id, 20, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id, $this->vehicleStock->id);

        return $inv->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->visit);
    }

    private function financeUser(): User
    {
        return $this->panelUser(['finance'], 'finance@test.local');
    }

    /** @param array<int, string> $permissions */
    private function panelUser(array $permissions, string $email): User
    {
        $user = User::create([
            'name' => 'Panel '.$email, 'email' => $email, 'password' => Hash::make('Strong-Panel9!'),
            'role' => User::ROLE_ADMIN, 'is_active' => true,
            'operating_company_id' => $this->operatingCompany->id,
            'permissions' => $permissions,
        ]);
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    private function otherCompanyOwner(): User
    {
        $company = OperatingCompany::create(['name' => 'Other Tenant', 'currency' => 'SAR', 'is_active' => true]);
        $branch = OperatingBranch::create(['operating_company_id' => $company->id, 'name' => 'Other', 'code' => 'OTH', 'is_active' => true]);
        Client::withoutGlobalScopes()->create([
            'name' => 'Other Client', 'category' => 'restaurant', 'payment_term' => 'monthly',
            'operating_company_id' => $company->id, 'operating_branch_id' => $branch->id, 'is_active' => true,
        ]);
        $owner = User::create([
            'name' => 'Other Owner', 'email' => 'other-owner@test.local', 'password' => Hash::make('Strong-Owner9!'),
            'role' => User::ROLE_OWNER, 'is_active' => true,
            'operating_company_id' => $company->id, 'operating_branch_id' => $branch->id,
        ]);
        $owner->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $owner;
    }
}
