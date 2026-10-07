<?php

namespace Tests\Feature;

use App\Models\NotificationMessage;
use App\Models\Visit;
use App\Services\SlaCalculator;
use Carbon\CarbonImmutable;
use Tests\DarakTestCase;

/**
 * 0.3.0 regression: the board kept counting SLA on finished visits, so a visit
 * closed at 10:30 inside an 11:00 deadline turned red in the afternoon and
 * climbed into "today's priorities". Finished work now carries a verdict fixed
 * at the moment it finished — on the board, in the app's bootstrap and in the
 * SLA sweep — while open work keeps its live, service-window-aware countdown.
 */
class SlaFrozenOnFinishedWorkTest extends DarakTestCase
{
    private function day(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-07 '.$time);
    }

    /** The fixture visit: reported at 07:00 with a 4-hour budget, due 11:00. */
    private function visitDueAt11(string $state, ?string $closedAt = null, string $workOrderStatus = 'scheduled'): Visit
    {
        $this->visit->workOrder->forceFill([
            'sla_minutes_budget' => 240,
            'sla_due_at' => $this->day('11:00'),
            'status' => $workOrderStatus,
        ])->save();

        $this->visit->forceFill([
            'scheduled_start' => $this->day('08:00'),
            'scheduled_end' => $this->day('10:00'),
            'state' => $state,
            'state_changed_at' => $closedAt ? $this->day($closedAt) : $this->day('08:00'),
            'ended_at' => $closedAt ? $this->day($closedAt) : null,
            'closed_at' => $closedAt ? $this->day($closedAt) : null,
        ])->save();

        return $this->visit->refresh()->load('workOrder.contract');
    }

    public function test_a_visit_completed_inside_its_deadline_stays_met_all_day(): void
    {
        $this->visitDueAt11(Visit::STATE_COMPLETED, '10:30');

        foreach (['10:45', '15:00', '22:30'] as $time) {
            $this->travelTo($this->day($time));

            $this->actingAs($this->owner, 'web')
                ->get(route('panel.board', ['date' => '2026-10-07']))
                ->assertOk()
                ->assertSee('أُنجزت ضمن المهلة')
                ->assertDontSee('متأخرة')
                ->assertDontSee('د متبقية')
                ->assertViewHas('rows', fn ($rows) => $rows->first()['sla_status'] === 'green'
                    && $rows->first()['sla_live'] === false
                    && $rows->first()['sla_outcome'] === SlaCalculator::OUTCOME_MET)
                ->assertViewHas('counts', fn (array $counts) => $counts['red'] === 0 && $counts['amber'] === 0)
                ->assertViewHas('priorities', fn ($priorities) => $priorities->isEmpty());
        }
    }

    public function test_a_visit_completed_after_its_deadline_is_breached_without_a_running_count(): void
    {
        $this->visitDueAt11(Visit::STATE_COMPLETED, '12:00');

        foreach (['12:30', '18:00'] as $time) {
            $this->travelTo($this->day($time));

            $this->actingAs($this->owner, 'web')
                ->get(route('panel.board', ['date' => '2026-10-07']))
                ->assertOk()
                // The overrun at close (60 min), not a number growing all day.
                ->assertSee('أُنجزت متأخرة 60 د')
                ->assertViewHas('rows', fn ($rows) => $rows->first()['sla_status'] === 'red'
                    && $rows->first()['sla_live'] === false
                    && $rows->first()['sla_remaining'] === -60)
                // A verdict, not an emergency: no KPI alarm, no priority entry.
                ->assertViewHas('counts', fn (array $counts) => $counts['red'] === 0)
                ->assertViewHas('priorities', fn ($priorities) => $priorities->isEmpty());
        }
    }

    public function test_open_work_past_its_deadline_still_counts_live(): void
    {
        $this->visitDueAt11(Visit::STATE_STARTED);
        $this->travelTo($this->day('12:00'));

        $this->actingAs($this->owner, 'web')
            ->get(route('panel.board', ['date' => '2026-10-07']))
            ->assertOk()
            ->assertSee('متأخرة 60 د')
            ->assertViewHas('rows', fn ($rows) => $rows->first()['sla_live'] === true && $rows->first()['sla_status'] === 'red')
            ->assertViewHas('counts', fn (array $counts) => $counts['red'] === 1)
            ->assertViewHas('priorities', fn ($priorities) => $priorities->count() === 1);
    }

    public function test_the_app_bootstrap_sla_block_is_frozen_for_finished_work(): void
    {
        $this->visitDueAt11(Visit::STATE_COMPLETED, '10:30');

        foreach (['15:00', '22:00'] as $time) {
            $this->travelTo($this->day($time));

            $sla = $this->actingAs($this->technician)
                ->getJson('/api/v1/sync/bootstrap')
                ->assertOk()
                ->json('visits.0.sla');

            $this->assertSame('green', $sla['status'], "at {$time}");
            $this->assertFalse($sla['live']);
            $this->assertSame('met', $sla['outcome']);
            $this->assertSame(30, $sla['remaining_minutes'], 'the margin at close');
        }

        // Reopened, the work is open again and the clock runs.
        $this->visit->forceFill(['state' => Visit::STATE_REOPENED, 'closed_at' => null])->save();
        $sla = $this->actingAs($this->technician)->getJson('/api/v1/sync/bootstrap')->json('visits.0.sla');
        $this->assertTrue($sla['live']);
        $this->assertSame('red', $sla['status']);
        $this->assertNull($sla['outcome']);
    }

    public function test_the_sweep_never_warns_about_finished_work(): void
    {
        $this->travelTo($this->day('10:45'));

        // Completed inside the deadline, 15 minutes before it.
        $this->visitDueAt11(Visit::STATE_COMPLETED, '10:30');
        $this->artisan('darak:sla-sweep')->assertSuccessful();

        // Work order closed or cancelled while the visit row is still open.
        foreach (['closed', 'cancelled'] as $status) {
            $this->visitDueAt11(Visit::STATE_STARTED, null, $status);
            $this->artisan('darak:sla-sweep')->assertSuccessful();
        }

        $this->assertSame(0, NotificationMessage::where('type', NotificationMessage::TYPE_SLA_AT_RISK)->count());

        // The same visit, open: 15 of 240 minutes left — warned.
        $this->visitDueAt11(Visit::STATE_STARTED);
        $this->artisan('darak:sla-sweep')->assertSuccessful();
        $this->assertSame(1, NotificationMessage::where('type', NotificationMessage::TYPE_SLA_AT_RISK)->count());
    }

    public function test_the_verdict_uses_the_service_window_aware_deadline(): void
    {
        $sla = app(SlaCalculator::class);

        // Reported 21:30 with 4 hours: 90 minutes tonight, 150 from 07:00 → due 09:30.
        $due = $sla->dueAt($this->day('21:30'), 240);
        $this->assertSame('2026-10-08 09:30', $due->format('Y-m-d H:i'));

        $this->visit->workOrder->forceFill(['sla_due_at' => $due, 'sla_minutes_budget' => 240])->save();
        $this->visit->forceFill([
            'state' => Visit::STATE_COMPLETED,
            'closed_at' => $this->day('09:00')->addDay(),
            'ended_at' => $this->day('09:00')->addDay(),
        ])->save();
        $visit = $this->visit->refresh()->load('workOrder.contract');

        // Eleven and a half wall-clock hours after the report, yet inside the
        // budget — and still "met" days later.
        $state = $sla->forVisit($visit, $this->day('12:00')->addDays(3));
        $this->assertSame(['green', false, 'met', 30], [
            $state['status'], $state['live'], $state['outcome'], $state['remaining_minutes'],
        ]);
    }

    public function test_a_cancelled_work_order_has_no_verdict_and_no_countdown(): void
    {
        $visit = $this->visitDueAt11(Visit::STATE_SCHEDULED, null, 'cancelled');

        $state = app(SlaCalculator::class)->forVisit($visit, $this->day('15:00'));

        $this->assertSame('grey', $state['status']);
        $this->assertFalse($state['live']);
        $this->assertSame(SlaCalculator::OUTCOME_CANCELLED, $state['outcome']);
        $this->assertNull($state['remaining_minutes']);
    }

    public function test_no_sla_means_no_block(): void
    {
        $this->visit->workOrder->forceFill(['sla_due_at' => null])->save();

        $this->assertNull(app(SlaCalculator::class)->forVisit($this->visit->refresh(), now()));
    }
}
