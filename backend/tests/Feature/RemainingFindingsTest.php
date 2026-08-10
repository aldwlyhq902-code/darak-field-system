<?php

namespace Tests\Feature;

use App\Models\NotificationMessage;
use App\Models\Part;
use App\Models\Subcontractor;
use App\Models\User;
use App\Models\Visit;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\NotificationService;
use App\Services\SubcontractorService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * The tail of the review: individually small, collectively the difference
 * between a system that behaves and one that surprises you in month three.
 */
class RemainingFindingsTest extends DarakTestCase
{
    public function test_a_technician_reassigned_back_is_notified_again(): void
    {
        $this->visit->forceFill([
            'scheduled_start' => now()->setTime(9, 0),
            'scheduled_end' => now()->setTime(11, 0),
        ])->save();

        // Morning technician -> another -> back to the morning technician.
        $evening = $this->otherTechnician;
        $evening->forceFill(['shift_start' => '07:00', 'shift_end' => '15:00'])->save();

        foreach ([$this->technician, $evening, $this->technician] as $assignee) {
            $this->actingAs($this->owner, 'web')
                ->post(route('panel.visit.assign', $this->visit), ['user_id' => $assignee->id]);
        }

        $forFirst = NotificationMessage::where('type', NotificationMessage::TYPE_VISIT_ASSIGNED)
            ->where('user_id', $this->technician->id)
            ->count();

        $this->assertSame(2, $forFirst, 'coming back to a visit must notify again');
    }

    public function test_sla_warning_fires_again_for_a_new_risk_episode(): void
    {
        $notifications = app(NotificationService::class);

        $this->visit->workOrder->forceFill(['sla_due_at' => now()->addHour()])->save();
        $notifications->slaAtRisk($this->visit->refresh(), 30);
        $notifications->slaAtRisk($this->visit->refresh(), 25);

        $this->assertSame(1, NotificationMessage::where('type', NotificationMessage::TYPE_SLA_AT_RISK)->count());

        // The work order is re-dated: a new episode, and the supervisor must be
        // told about it rather than the key staying consumed forever.
        $this->visit->workOrder->forceFill(['sla_due_at' => now()->addDays(2)])->save();
        $notifications->slaAtRisk($this->visit->refresh(), 20);

        $this->assertSame(2, NotificationMessage::where('type', NotificationMessage::TYPE_SLA_AT_RISK)->count());
    }

    public function test_a_contract_holder_is_billed_the_member_price(): void
    {
        $this->part->forceFill(['member_price' => 76])->save();

        $inv = app(InventoryService::class);
        $inv->receipt((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id, $this->vehicleStock->id);
        $inv->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->visit);

        $invoice = app(InvoiceService::class)->invoiceVisit($this->visit->refresh());

        // 2 x 76, not 2 x 95. The member price was stored and shown but never
        // actually charged.
        $this->assertEqualsWithDelta(152.0, (float) $invoice->amount, 0.01);
    }

    public function test_a_visit_without_a_contract_pays_list_price(): void
    {
        $this->part->forceFill(['member_price' => 76])->save();
        $this->visit->workOrder->forceFill(['contract_id' => null])->save();

        $inv = app(InventoryService::class);
        $inv->receipt((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id, $this->vehicleStock->id);
        $inv->issueToVisit((string) Str::uuid(), $this->part->id, 2, $this->vehicleStock->id, $this->visit);

        $invoice = app(InvoiceService::class)->invoiceVisit($this->visit->refresh());

        $this->assertEqualsWithDelta(190.0, (float) $invoice->amount, 0.01);
    }

    public function test_credit_notes_for_a_full_return_sum_exactly_to_the_invoiced_vat(): void
    {
        $inv = app(InventoryService::class);
        $invoices = app(InvoiceService::class);

        $inv->receipt((string) Str::uuid(), $this->part->id, 20, $this->warehouse->id);
        $inv->loadVehicle((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id, $this->vehicleStock->id);
        $issue = $inv->issueToVisit((string) Str::uuid(), $this->part->id, 3, $this->vehicleStock->id, $this->visit);

        $invoice = $invoices->invoiceVisit($this->visit->refresh());

        // Returned one at a time, so the VAT is split three ways.
        $creditedVat = 0.0;

        for ($i = 0; $i < 3; $i++) {
            $return = $inv->returnFromVisit((string) Str::uuid(), $issue, 1, $this->vehicleStock->id);
            $creditedVat += (float) $invoices->creditNoteForReturn($return)->vat_amount;
        }

        $this->assertEqualsWithDelta(
            (float) $invoice->vat_amount,
            $creditedVat,
            0.001,
            'a fully returned invoice must reverse to the halala',
        );
    }

    public function test_a_partner_with_history_cannot_be_erased(): void
    {
        $partner = Subcontractor::create(['name' => 'شريك الهود', 'is_active' => true]);

        app(SubcontractorService::class)
            ->assign($partner, $this->visit->workOrder, 600, 1000, $this->visit);

        $this->expectExceptionMessage('لا يمكن حذف شريك');
        $partner->forceDelete();
    }

    public function test_subcontractor_order_numbers_follow_their_own_id(): void
    {
        $partner = Subcontractor::create(['name' => 'شريك', 'is_active' => true]);
        $service = app(SubcontractorService::class);

        $first = $service->assign($partner, $this->visit->workOrder, 100, 200, $this->visit);
        $second = $service->assign($partner, $this->visit->workOrder, 100, 200, $this->visit);

        $this->assertNotSame($first->order_no, $second->order_no);
        $this->assertSame('SUB-' . str_pad((string) $first->id, 5, '0', STR_PAD_LEFT), $first->order_no);
        $this->assertStringNotContainsString('TMP', $second->order_no);
    }

    public function test_yesterdays_open_visit_still_reaches_the_device(): void
    {
        // Scheduled yesterday and never closed — the technician is still on it.
        $this->visit->forceFill([
            'scheduled_start' => now()->subDay()->setTime(23, 0),
            'scheduled_end' => now()->subDay()->setTime(23, 59),
            'state' => Visit::STATE_STARTED,
        ])->save();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'tech1@test.local',
            'password' => 'secret',
            'device_uuid' => $this->device->device_uuid,
        ])->json('token');

        $ids = collect($this->asToken($token)->getJson('/api/v1/sync/bootstrap')->json('visits'))
            ->pluck('id');

        $this->assertContains($this->visit->id, $ids, 'an unfinished visit must not vanish at midnight');
    }

    public function test_an_admin_cannot_revoke_devices_or_manage_the_team(): void
    {
        $admin = User::create([
            'name' => 'إداري',
            'email' => 'admin2@test.local',
            'password' => Hash::make('secret'),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'web')
            ->post(route('panel.team.revoke', $this->device))
            ->assertStatus(403);

        $this->actingAs($admin, 'web')
            ->post(route('panel.team.toggle', $this->technician))
            ->assertStatus(403);

        $this->assertNull($this->device->refresh()->revoked_at);
    }

    public function test_a_replayed_movement_under_concurrency_is_a_no_op_not_an_error(): void
    {
        $inv = app(InventoryService::class);
        $key = (string) Str::uuid();

        $inv->receipt($key, $this->part->id, 5, $this->warehouse->id);
        $second = $inv->receipt($key, $this->part->id, 5, $this->warehouse->id);

        $this->assertEqualsWithDelta(5.0, $inv->balance($this->part->id, $this->warehouse->id), 0.001);
        $this->assertNotNull($second->id);
    }

    public function test_location_balances_are_computed_in_a_bounded_number_of_queries(): void
    {
        $inv = app(InventoryService::class);

        for ($i = 0; $i < 12; $i++) {
            $part = Part::create([
                'sku' => "BULK-{$i}",
                'name' => "صنف {$i}",
                'purchase_cost' => 10,
                'sale_price' => 20,
                'reorder_level' => 1,
            ]);

            $inv->receipt((string) Str::uuid(), $part->id, 3, $this->warehouse->id);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $balances = $inv->locationBalances($this->warehouse->id);
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertCount(12, $balances);
        $this->assertLessThanOrEqual(
            3,
            $queries,
            'balances must not run two SUMs per part — that degrades with every movement ever recorded',
        );
    }
}
