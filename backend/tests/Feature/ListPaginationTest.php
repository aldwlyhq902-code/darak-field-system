<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\ContractInstallment;
use App\Models\EmployeeDocument;
use App\Models\NotificationMessage;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\SalesLead;
use App\Models\Supplier;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/** Lists page instead of silently truncating; KPIs come from aggregates over all rows. */
class ListPaginationTest extends DarakTestCase
{
    public function test_notification_lists_page_independently_past_the_old_cap(): void
    {
        $rows = [];
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = [
                'type' => 'sla.at_risk', 'channel' => 'in_app', 'recipient_kind' => 'supervisor', 'user_id' => $this->owner->id,
                'body' => "message {$i}", 'status' => 'queued', 'attempts' => 0, 'idempotency_key' => 'test:'.$i,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        NotificationMessage::insert($rows);

        $response = $this->actingAs($this->owner, 'web')->get(route('panel.notifications'))->assertOk()
            ->assertSee('في الطابور (120)');
        $this->assertSame(120, $response->viewData('queued')->total(), 'was capped at 100 rows');
        $this->assertStringContainsString('queued_page=2', $response->getContent());

        $this->get(route('panel.notifications', ['queued_page' => 3]))->assertOk()->assertSee('message 120');
    }

    public function test_hr_and_fleet_kpis_count_every_row_not_the_page(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            EmployeeDocument::create([
                'user_id' => $this->technician->id, 'operating_branch_id' => $this->operatingBranch->id,
                'type' => 'iqama', 'document_number' => 'D'.$i, 'expires_on' => now()->addDays(10), 'status' => 'active',
            ]);
        }
        $hr = $this->actingAs($this->owner, 'web')->get(route('panel.hr'))->assertOk();
        $this->assertSame(35, $hr->viewData('expiringDocumentsCount'));
        $this->assertCount(30, $hr->viewData('expiringDocuments')->items());

        for ($i = 1; $i <= 24; $i++) {
            Vehicle::create(['plate' => 'PG-'.$i, 'internal_code' => 'P'.$i, 'operating_branch_id' => $this->operatingBranch->id]);
        }
        $fleet = $this->get(route('panel.fleet'))->assertOk();
        $this->assertSame(25, $fleet->viewData('vehicleCount'));
        $this->assertCount(20, $fleet->viewData('vehicles')->items());
        $this->assertStringContainsString('vehicles_page=2', $fleet->getContent());
    }

    public function test_back_office_lists_page_with_named_pages(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            Client::create(['name' => sprintf('Client %03d', $i), 'category' => 'restaurant', 'operating_company_id' => $this->operatingCompany->id, 'operating_branch_id' => $this->operatingBranch->id]);
        }
        $clients = $this->actingAs($this->owner, 'web')->get(route('panel.clients'))->assertOk()->assertSee('العملاء (56)');
        $this->assertCount(50, $clients->viewData('clients')->items());
        $this->get(route('panel.clients', ['q' => 'Client 05']))->assertOk()->assertSee('Client 055')->assertDontSee('Client 044');

        $supplier = Supplier::create(['name' => 'Paged Supplier', 'lead_time_days' => 1, 'is_active' => true]);
        for ($i = 1; $i <= 45; $i++) {
            PurchaseOrder::create(['po_number' => 'PO-PAGE-'.$i, 'supplier_id' => $supplier->id, 'destination_location_id' => $this->warehouse->id, 'ordered_on' => now(), 'status' => 'ordered', 'subtotal' => 1, 'vat_amount' => 0, 'total_amount' => 1, 'created_by' => $this->owner->id]);
        }
        $procurement = $this->get(route('panel.procurement'))->assertOk();
        $this->assertSame(45, $procurement->viewData('orders')->total(), 'was capped at 40');
        $this->assertStringContainsString('orders_page=2', $procurement->getContent());

        for ($i = 1; $i <= 60; $i++) {
            SalesLead::create(['lead_no' => 'LEAD-P'.$i, 'company_name' => 'Lead '.$i, 'stage' => 'new', 'estimated_value' => 0, 'operating_branch_id' => $this->operatingBranch->id]);
        }
        $admin = $this->get(route('panel.admin-operations'))->assertOk();
        $this->assertSame(60, $admin->viewData('leads')->total(), 'was capped at 50');
        $this->assertStringContainsString('leads_page=2', $admin->getContent());
    }

    public function test_every_touched_page_renders_without_leaking_blade_directives(): void
    {
        $this->actingAs($this->owner, 'web');
        $pages = [
            route('panel.notifications'), route('panel.hr'), route('panel.hr.work-policies'), route('panel.fleet'),
            route('panel.clients'), route('panel.procurement'), route('panel.admin-operations'), route('panel.commissions'),
            route('panel.operations'), route('panel.operations', ['tab' => 'requests']), route('panel.operations', ['tab' => 'quality']),
            route('panel.operations', ['tab' => 'resources']), route('panel.intelligence'), route('panel.inventory'),
            route('panel.maintenance'), route('panel.team'), route('panel.sub'), route('panel.finance'), route('panel.commercial'),
            route('panel.invoices'), route('panel.visit', $this->visit), route('sales.home'),
        ];
        foreach ($pages as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach (['@include(', '@endforeach', '@endforelse', '@endif'] as $directive) {
                $this->assertStringNotContainsString($directive, $html, "{$url} leaks {$directive}");
            }
        }

        $portal = ClientPortalUser::create(['client_id' => $this->client->id, 'name' => 'Portal', 'email' => 'portal-render@test.local', 'password' => Hash::make('Strong-Client9!'), 'is_active' => true]);
        $html = $this->actingAs($portal, 'client')->get(route('client.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('@include(', $html);
    }

    public function test_client_portal_receipts_page_instead_of_the_first_twenty(): void
    {
        $installment = ContractInstallment::create(['contract_id' => $this->contract->id, 'installment_no' => 1, 'due_on' => now(), 'amount' => 1000, 'vat_amount' => 0, 'total_amount' => 1000, 'paid_amount' => 0, 'status' => 'pending']);
        for ($i = 1; $i <= 23; $i++) {
            Payment::create(['receipt_no' => 'RCPT-P'.Str::padLeft((string) $i, 3, '0'), 'contract_installment_id' => $installment->id, 'client_id' => $this->client->id, 'amount' => 1, 'paid_on' => now()->subDays($i), 'method' => 'cash']);
        }
        $portal = ClientPortalUser::create(['client_id' => $this->client->id, 'name' => 'Portal', 'email' => 'portal-pages@test.local', 'password' => Hash::make('Strong-Client9!'), 'is_active' => true]);

        $home = $this->actingAs($portal, 'client')->get(route('client.home'))->assertOk();
        $this->assertSame(23, $home->viewData('payments')->total());
        $this->assertStringContainsString('receipts_page=2', $home->getContent());
        $this->get(route('client.home', ['receipts_page' => 3]))->assertOk()->assertSee('RCPT-P023');
    }
}
