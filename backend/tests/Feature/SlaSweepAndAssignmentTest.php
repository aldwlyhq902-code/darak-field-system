<?php

namespace Tests\Feature;

use App\Models\NotificationMessage;
use App\Models\StockMove;
use App\Services\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Covers the wiring that existed only on paper until now: assignment actually
 * notifying, the SLA sweep respecting the service window, and stocktake.
 */
class SlaSweepAndAssignmentTest extends DarakTestCase
{
    public function test_assigning_from_the_panel_queues_a_message_for_the_technician(): void
    {
        $this->visit->forceFill([
            'assigned_user_id' => null,
            'scheduled_start' => now()->setTime(9, 0),
            'scheduled_end' => now()->setTime(11, 0),
        ])->save();

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.visit.assign', $this->visit), ['user_id' => $this->technician->id])
            ->assertSessionHas('ok');

        $message = NotificationMessage::where('type', NotificationMessage::TYPE_VISIT_ASSIGNED)->first();

        $this->assertNotNull($message, 'assignment must notify the technician');
        $this->assertSame($this->technician->id, $message->user_id);
        $this->assertStringContainsString($this->client->name, $message->body);
    }

    public function test_reassigning_to_the_same_technician_does_not_duplicate_the_message(): void
    {
        $this->visit->forceFill([
            'scheduled_start' => now()->setTime(9, 0),
            'scheduled_end' => now()->setTime(11, 0),
        ])->save();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->owner, 'web')
                ->post(route('panel.visit.assign', $this->visit), ['user_id' => $this->technician->id]);
        }

        $this->assertSame(1, NotificationMessage::where('type', NotificationMessage::TYPE_VISIT_ASSIGNED)->count());
    }

    public function test_sla_sweep_warns_when_the_budget_is_nearly_spent(): void
    {
        $this->travelTo(CarbonImmutable::now()->setTime(10, 0));

        $this->visit->workOrder->forceFill([
            'sla_minutes_budget' => 240,
            'sla_due_at' => now()->addMinutes(30), // 12.5% of the budget left
        ])->save();

        $this->artisan('darak:sla-sweep')->assertSuccessful();

        $this->assertSame(1, NotificationMessage::where('type', NotificationMessage::TYPE_SLA_AT_RISK)->count());
    }

    public function test_sla_sweep_stays_quiet_when_plenty_of_time_remains(): void
    {
        $this->travelTo(CarbonImmutable::now()->setTime(10, 0));

        $this->visit->workOrder->forceFill([
            'sla_minutes_budget' => 240,
            'sla_due_at' => now()->addMinutes(200),
        ])->save();

        $this->artisan('darak:sla-sweep')->assertSuccessful();

        $this->assertSame(0, NotificationMessage::where('type', NotificationMessage::TYPE_SLA_AT_RISK)->count());
    }

    public function test_sla_sweep_is_silent_outside_the_service_window(): void
    {
        // 02:00 — the clock is frozen, so warning now would be a lie.
        $this->travelTo(CarbonImmutable::now()->setTime(2, 0));

        $this->visit->workOrder->forceFill([
            'sla_minutes_budget' => 240,
            'sla_due_at' => now()->addMinutes(5),
        ])->save();

        $this->artisan('darak:sla-sweep')->assertSuccessful();

        $this->assertSame(0, NotificationMessage::where('type', NotificationMessage::TYPE_SLA_AT_RISK)->count());
    }

    public function test_a_persistent_risk_produces_one_warning_not_one_per_sweep(): void
    {
        $this->travelTo(CarbonImmutable::now()->setTime(10, 0));

        $this->visit->workOrder->forceFill([
            'sla_minutes_budget' => 240,
            'sla_due_at' => now()->addMinutes(20),
        ])->save();

        $this->artisan('darak:sla-sweep');
        $this->artisan('darak:sla-sweep');
        $this->artisan('darak:sla-sweep');

        $this->assertSame(1, NotificationMessage::where('type', NotificationMessage::TYPE_SLA_AT_RISK)->count());
    }

    public function test_notifications_run_command_leaves_manual_whatsapp_for_a_human(): void
    {
        app(\App\Services\NotificationService::class)->reportReady($this->visit, '0551234567');
        app(\App\Services\NotificationService::class)->slaAtRisk($this->visit, 20);

        $this->artisan('darak:notifications-run')->assertSuccessful();

        $this->assertSame('sent', NotificationMessage::where('type', NotificationMessage::TYPE_SLA_AT_RISK)->first()->status);
        $this->assertSame('queued', NotificationMessage::where('type', NotificationMessage::TYPE_REPORT_READY)->first()->status);
    }

    public function test_stocktake_shortfall_is_recorded_as_its_own_movement(): void
    {
        $inventory = app(InventoryService::class);
        $inventory->receipt((string) Str::uuid(), $this->part->id, 10, $this->warehouse->id);

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.inventory.adjust'), [
                'part_id' => $this->part->id,
                'location_id' => $this->warehouse->id,
                'counted_qty' => 7,
                'reason' => 'جرد شهري',
            ])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'عجز'));

        $this->assertEqualsWithDelta(7.0, $inventory->balance($this->part->id, $this->warehouse->id), 0.001);

        // History is never edited — the difference is its own row.
        $adjustment = StockMove::where('move_type', StockMove::ADJUSTMENT)->first();
        $this->assertNotNull($adjustment);
        $this->assertEqualsWithDelta(3.0, (float) $adjustment->qty, 0.001);
        $this->assertStringContainsString('جرد شهري', $adjustment->note);
    }

    public function test_stocktake_surplus_moves_stock_in(): void
    {
        $inventory = app(InventoryService::class);
        $inventory->receipt((string) Str::uuid(), $this->part->id, 4, $this->warehouse->id);

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.inventory.adjust'), [
                'part_id' => $this->part->id,
                'location_id' => $this->warehouse->id,
                'counted_qty' => 6,
                'reason' => 'وجدت قطعتان',
            ])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'زيادة'));

        $this->assertEqualsWithDelta(6.0, $inventory->balance($this->part->id, $this->warehouse->id), 0.001);
    }

    public function test_a_matching_stocktake_creates_no_movement(): void
    {
        $inventory = app(InventoryService::class);
        $inventory->receipt((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id);

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.inventory.adjust'), [
                'part_id' => $this->part->id,
                'location_id' => $this->warehouse->id,
                'counted_qty' => 5,
                'reason' => 'جرد',
            ])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'يطابق'));

        $this->assertSame(0, StockMove::where('move_type', StockMove::ADJUSTMENT)->count());
    }
}
