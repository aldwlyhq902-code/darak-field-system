<?php

namespace Tests\Feature;

use App\Models\ClientPortalUser;
use App\Models\ContractInstallment;
use App\Models\Custody;
use App\Models\NotificationMessage;
use App\Models\Quotation;
use App\Services\CommercialService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

class CommercialOperationsTest extends DarakTestCase
{
    public function test_owner_can_create_send_and_convert_a_quotation_with_installments(): void
    {
        $quote = Quotation::create($this->quoteData());
        $quote->sites()->attach($this->site);
        $quote->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

        $contract = app(CommercialService::class)->convertQuotation($quote, $this->owner->id);

        $this->assertSame('converted', $quote->refresh()->status);
        $this->assertSame($contract->id, $quote->converted_contract_id);
        $this->assertCount(4, $contract->installments);
        $this->assertEqualsWithDelta(3450, (float) $contract->installments->first()->total_amount, .001);
    }

    public function test_client_can_only_accept_its_own_sent_valid_quote(): void
    {
        $portal = ClientPortalUser::create(['client_id' => $this->client->id, 'name' => 'Approver', 'email' => 'approver@test.local', 'password' => Hash::make('Strong-Client9!'), 'is_active' => true]);
        $quote = Quotation::create($this->quoteData());
        $quote->sites()->attach($this->site);

        $this->actingAs($portal, 'client')->post(route('client.quotation.accept', $quote), ['accept_terms' => 1])->assertStatus(422);
        $quote->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        $this->actingAs($portal, 'client')->post(route('client.quotation.accept', $quote), ['accept_terms' => 1])->assertRedirect();
        $this->assertSame('accepted', $quote->refresh()->status);
        $this->assertSame($portal->id, $quote->accepted_by_portal_user_id);
    }

    public function test_partial_and_full_payments_update_one_installment_without_overpayment(): void
    {
        $installment = ContractInstallment::create(['contract_id' => $this->contract->id, 'installment_no' => 1, 'due_on' => now(), 'amount' => 100, 'vat_amount' => 15, 'total_amount' => 115, 'paid_amount' => 0, 'status' => 'pending']);
        $service = app(CommercialService::class);
        $service->recordPayment($installment, ['amount' => 40, 'paid_on' => now()->toDateString(), 'method' => 'bank_transfer'], $this->owner->id);
        $this->assertSame('partial', $installment->refresh()->status);
        $service->recordPayment($installment, ['amount' => 75, 'paid_on' => now()->toDateString(), 'method' => 'cash'], $this->owner->id);
        $this->assertSame('paid', $installment->refresh()->status);
        $this->expectException(\RuntimeException::class);
        $service->recordPayment($installment, ['amount' => 1, 'paid_on' => now()->toDateString(), 'method' => 'cash'], $this->owner->id);
    }

    public function test_commercial_alerts_mark_overdue_and_queue_client_and_supervisor_messages(): void
    {
        ClientPortalUser::create(['client_id' => $this->client->id, 'name' => 'Finance', 'email' => 'finance@test.local', 'phone' => '0501234567', 'password' => Hash::make('Strong-Client9!'), 'is_active' => true]);
        ContractInstallment::create(['contract_id' => $this->contract->id, 'installment_no' => 1, 'due_on' => now()->subDay(), 'amount' => 100, 'vat_amount' => 15, 'total_amount' => 115, 'paid_amount' => 0, 'status' => 'pending']);
        $this->artisan('darak:commercial-alerts')->assertSuccessful();
        $this->assertDatabaseHas('contract_installments', ['status' => 'overdue']);
        $this->assertSame(2, NotificationMessage::where('type', 'installment.due')->count());
    }

    public function test_custody_links_vehicle_and_can_be_returned_with_condition(): void
    {
        $vehicle = $this->vehicleStock->vehicle;
        $this->actingAs($this->owner, 'web')->post(route('panel.team.custody.issue'), ['user_id' => $this->otherTechnician->id, 'item_type' => 'vehicle', 'item_name' => 'سيارة الخدمة', 'vehicle_id' => $vehicle->id, 'stock_location_id' => $this->vehicleStock->id, 'condition_out' => 'سليمة'])->assertRedirect();
        $custody = Custody::firstOrFail();
        $this->assertSame($this->otherTechnician->id, $vehicle->refresh()->assigned_user_id);
        $this->actingAs($this->owner, 'web')->post(route('panel.team.custody.accept', $custody))->assertRedirect();
        $this->actingAs($this->owner, 'web')->post(route('panel.team.custody.return', $custody), ['condition_in' => 'سليمة مع خدش بسيط'])->assertRedirect();
        $this->assertSame('returned', $custody->refresh()->status);
        $this->assertNull($vehicle->refresh()->assigned_user_id);
    }

    public function test_commercial_and_operations_pages_render(): void
    {
        $this->actingAs($this->owner, 'web')->get(route('panel.commercial'))->assertOk()->assertSee('العروض والتحصيل');
        $this->actingAs($this->owner, 'web')->get(route('panel.operations'))->assertOk()->assertSee('تقويم الفنيين الأسبوعي');
    }

    private function quoteData(): array
    {
        return ['series_uuid' => (string) Str::uuid(), 'version' => 1, 'quote_no' => 'QT-TEST-1', 'client_id' => $this->client->id, 'title' => 'Annual maintenance', 'package_code' => 'basic', 'price_amount' => 1000, 'vat_rate' => .15, 'billing_cycle' => 'quarterly', 'duration_months' => 12, 'starts_on' => now()->addWeek()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(), 'service_window_start' => '07:00', 'service_window_end' => '23:00', 'sla_minutes' => 240, 'status' => 'draft', 'created_by' => $this->owner->id];
    }
}
