<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Part;
use App\Models\Visit;
use App\Models\WorkOrder;
use Tests\DarakTestCase;

/**
 * The supervisor panel. Two things are being pinned here: that technicians cannot
 * reach it at all, and that the data-entry paths produce records the field app can
 * actually consume.
 */
class PanelTest extends DarakTestCase
{
    public function test_technician_cannot_sign_in_to_the_panel(): void
    {
        $this->post('/login', ['email' => 'tech1@test.local', 'password' => 'secret'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    public function test_supervisor_can_sign_in_and_see_the_board(): void
    {
        $this->post('/login', ['email' => 'owner@test.local', 'password' => 'secret'])
            ->assertRedirect(route('panel.board'));

        $this->actingAs($this->owner, 'web')
            ->get(route('panel.board'))
            ->assertOk()
            ->assertSee('لوحة اليوم');
    }

    public function test_board_is_closed_to_guests(): void
    {
        $this->get(route('panel.board'))->assertRedirect(route('panel.login'));
    }

    public function test_board_shows_todays_visits_with_sla_state(): void
    {
        $this->visit->forceFill(['scheduled_start' => now()->addHour()])->save();

        $this->actingAs($this->owner, 'web')
            ->get(route('panel.board'))
            ->assertOk()
            ->assertSee($this->client->name)
            ->assertSee('حل من أول زيارة');
    }

    public function test_creating_a_client_also_creates_its_first_site(): void
    {
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.clients.store'), [
                'name' => 'مطعم اختبار',
                'category' => 'restaurant',
                'established_on' => '2015-01-01',
                'site_name' => 'الفرع الرئيسي',
                'site_address' => 'جدة',
            ])
            ->assertRedirect();

        $client = Client::where('name', 'مطعم اختبار')->first();

        $this->assertNotNull($client);
        $this->assertCount(1, $client->sites);
        $this->assertStringStartsWith('SITE-', $client->sites->first()->qr_code);
    }

    public function test_a_restaurant_under_one_year_old_is_flagged_for_advance_payment(): void
    {
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.clients.store'), [
                'name' => 'مقهى جديد',
                'category' => 'cafe',
                'established_on' => now()->subMonths(5)->toDateString(),
                'site_name' => 'الفرع',
            ])
            ->assertSessionHas('ok', fn ($message) => str_contains($message, 'أقل من سنة'));
    }

    public function test_contract_is_stored_pre_vat_with_a_service_window(): void
    {
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.client.contract', $this->client), [
                'package_code' => 'comprehensive',
                'price_amount' => 1850,
                'starts_on' => now()->toDateString(),
                'sla_minutes' => 240,
                'service_window_start' => '07:00',
                'service_window_end' => '23:00',
                'is_trial' => '1',
                'site_ids' => [$this->site->id],
            ])
            ->assertRedirect();

        $contract = Contract::where('package_code', 'comprehensive')->latest('id')->first();

        $this->assertEqualsWithDelta(1850.0, (float) $contract->price_amount, 0.01);
        $this->assertEqualsWithDelta(2127.5, $contract->priceInclVat(), 0.01);
        $this->assertTrue($contract->is_trial);
        $this->assertNotNull($contract->decision_due_on, 'a trial must carry its decision date');
        $this->assertNotEmpty($contract->exclusions);
    }

    public function test_creating_a_visit_computes_the_sla_inside_the_service_window(): void
    {
        // 21:30 with a four-hour budget must land the next morning, not at 01:30.
        $this->travelTo(now()->setTime(21, 30));

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.client.visit', $this->client), [
                'site_id' => $this->site->id,
                'contract_id' => $this->contract->id,
                'type' => 'reactive',
                'title' => 'بلاغ مسائي',
                'scheduled_start' => now()->addHour()->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect();

        $workOrder = WorkOrder::latest('id')->first();

        $this->assertSame('09:30', $workOrder->sla_due_at->format('H:i'));
        $this->assertSame(1, Visit::where('work_order_id', $workOrder->id)->count());
    }

    public function test_assignment_is_refused_outside_the_technicians_shift(): void
    {
        // The visit sits in the morning; this technician works the evening shift.
        $this->visit->forceFill([
            'scheduled_start' => now()->setTime(9, 0),
            'scheduled_end' => now()->setTime(11, 0),
        ])->save();

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.visit.assign', $this->visit), ['user_id' => $this->otherTechnician->id])
            ->assertSessionHas('err', fn ($message) => str_contains($message, 'خارج ورديته'));

        $this->assertNotSame($this->otherTechnician->id, $this->visit->refresh()->assigned_user_id);
    }

    public function test_part_requires_a_purchase_cost(): void
    {
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.inventory.part'), [
                'sku' => 'NO-COST-1',
                'name' => 'صنف بلا تكلفة',
                'unit' => 'pcs',
                'sale_price' => 100,
                'reorder_level' => 1,
            ])
            ->assertSessionHasErrors('purchase_cost');

        $this->assertNull(Part::where('sku', 'NO-COST-1')->first());
    }

    public function test_vehicle_load_beyond_warehouse_stock_is_refused_with_a_reason(): void
    {
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.inventory.transfer'), [
                'part_id' => $this->part->id,
                'qty' => 5,
                'from_location_id' => $this->warehouse->id,
                'to_location_id' => $this->vehicleStock->id,
            ])
            ->assertSessionHas('err', fn ($message) => str_contains($message, 'Insufficient stock'));
    }

    public function test_supervisor_can_revoke_a_device_from_the_panel(): void
    {
        $this->actingAs($this->owner, 'web')
            ->post(route('panel.team.revoke', $this->device), ['reason' => 'فُقد الجهاز'])
            ->assertRedirect();

        $this->assertNotNull($this->device->refresh()->revoked_at);
    }
}
