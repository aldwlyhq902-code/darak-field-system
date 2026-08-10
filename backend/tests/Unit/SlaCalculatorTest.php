<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Services\SlaCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The SLA countdown runs only inside the contract's service window.
 *
 * This is the fix for a contradiction found in review: dispatch forbids work ending
 * after 23:00, but the package promises a 4-hour response. A naive wall-clock
 * countdown would mark every evening ticket as breached by morning.
 */
class SlaCalculatorTest extends TestCase
{
    private SlaCalculator $sla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sla = new SlaCalculator();
    }

    public function test_evening_ticket_resumes_the_next_morning(): void
    {
        // 21:30 + 4h: 90 minutes today (until 23:00), 150 minutes from 07:00.
        $due = $this->sla->dueAt(CarbonImmutable::parse('2026-08-10 21:30'), 240);

        $this->assertSame('2026-08-11 09:30', $due->format('Y-m-d H:i'));
    }

    public function test_ticket_fully_inside_the_window_is_unaffected(): void
    {
        $due = $this->sla->dueAt(CarbonImmutable::parse('2026-08-10 09:00'), 240);

        $this->assertSame('2026-08-10 13:00', $due->format('Y-m-d H:i'));
    }

    public function test_ticket_before_opening_starts_counting_at_open(): void
    {
        $due = $this->sla->dueAt(CarbonImmutable::parse('2026-08-10 02:00'), 120);

        $this->assertSame('2026-08-10 09:00', $due->format('Y-m-d H:i'));
    }

    public function test_budget_spans_multiple_days_when_large(): void
    {
        // 20 hours of in-window time from 07:00, with a 16-hour window per day.
        $due = $this->sla->dueAt(CarbonImmutable::parse('2026-08-10 07:00'), 20 * 60);

        $this->assertSame('2026-08-11 11:00', $due->format('Y-m-d H:i'));
    }

    public function test_elapsed_minutes_skip_the_closed_period(): void
    {
        $elapsed = $this->sla->elapsedMinutes(
            CarbonImmutable::parse('2026-08-10 21:30'),
            CarbonImmutable::parse('2026-08-11 08:00'),
        );

        $this->assertSame(150, $elapsed); // 90 before closing + 60 after opening
    }

    public function test_remaining_minutes_go_negative_after_breach(): void
    {
        $due = CarbonImmutable::parse('2026-08-10 10:00');
        $now = CarbonImmutable::parse('2026-08-10 11:30');

        $this->assertSame(-90, $this->sla->remainingMinutes($now, $due));
    }

    public function test_status_colours_track_the_remaining_budget(): void
    {
        $due = CarbonImmutable::parse('2026-08-10 12:00');

        $this->assertSame('green', $this->sla->status(CarbonImmutable::parse('2026-08-10 08:00'), $due, 240));
        $this->assertSame('amber', $this->sla->status(CarbonImmutable::parse('2026-08-10 11:15'), $due, 240));
        $this->assertSame('red', $this->sla->status(CarbonImmutable::parse('2026-08-10 12:30'), $due, 240));
    }

    public function test_contract_can_narrow_the_window(): void
    {
        $contract = new Contract([
            'service_window_start' => '08:00:00',
            'service_window_end' => '17:00:00',
        ]);

        // 16:00 + 3h in a 08:00-17:00 window: 1 hour today, 2 from 08:00 tomorrow.
        $due = $this->sla->dueAt(CarbonImmutable::parse('2026-08-10 16:00'), 180, $contract);

        $this->assertSame('2026-08-11 10:00', $due->format('Y-m-d H:i'));
    }

    /**
     * An evening venue with a 15:00-02:00 window is legitimate and the schema
     * accepts it. It used to be read as a zero-length window, so dueAt fell back
     * to the wall clock while remainingMinutes always returned zero — every
     * ticket showed red from the moment it was created.
     */
    public function test_an_overnight_window_is_open_across_midnight(): void
    {
        $contract = new Contract([
            'service_window_start' => '15:00:00',
            'service_window_end' => '02:00:00',
        ]);

        $this->assertTrue($this->sla->isWithinWindow(CarbonImmutable::parse('2026-08-10 16:00'), $contract));
        $this->assertTrue($this->sla->isWithinWindow(CarbonImmutable::parse('2026-08-10 23:30'), $contract));
        $this->assertTrue($this->sla->isWithinWindow(CarbonImmutable::parse('2026-08-11 01:30'), $contract));
        $this->assertFalse($this->sla->isWithinWindow(CarbonImmutable::parse('2026-08-11 09:00'), $contract));
    }

    public function test_an_overnight_window_consumes_budget_across_midnight(): void
    {
        $contract = new Contract([
            'service_window_start' => '15:00:00',
            'service_window_end' => '02:00:00',
        ]);

        // 23:00 + 4h: three hours until the 02:00 close, then the last hour from
        // the next opening at 15:00.
        $due = $this->sla->dueAt(CarbonImmutable::parse('2026-08-10 23:00'), 240, $contract);

        $this->assertSame('2026-08-11 16:00', $due->format('Y-m-d H:i'));
    }

    public function test_an_overnight_ticket_is_not_instantly_breached(): void
    {
        $contract = new Contract([
            'service_window_start' => '15:00:00',
            'service_window_end' => '02:00:00',
        ]);

        $now = CarbonImmutable::parse('2026-08-10 22:00');
        $due = $this->sla->dueAt($now, 120, $contract);

        $this->assertGreaterThan(0, $this->sla->remainingMinutes($now, $due, $contract));
        $this->assertSame('green', $this->sla->status($now, $due, 120, $contract));
    }

    public function test_a_zero_length_window_falls_back_to_wall_clock_consistently(): void
    {
        $contract = new Contract([
            'service_window_start' => '08:00:00',
            'service_window_end' => '08:00:00',
        ]);

        $now = CarbonImmutable::parse('2026-08-10 12:00');
        $due = $this->sla->dueAt($now, 120, $contract);

        // dueAt and remainingMinutes must agree — disagreeing is what produced a
        // permanently red board.
        $this->assertSame('2026-08-10 14:00', $due->format('Y-m-d H:i'));
        $this->assertSame(120, $this->sla->remainingMinutes($now, $due, $contract));
        $this->assertFalse($this->sla->isWithinWindow($now, $contract));
    }

    public function test_window_boundaries_are_classified_correctly(): void
    {
        $this->assertTrue($this->sla->isWithinWindow(CarbonImmutable::parse('2026-08-10 07:00')));
        $this->assertTrue($this->sla->isWithinWindow(CarbonImmutable::parse('2026-08-10 22:59')));
        $this->assertFalse($this->sla->isWithinWindow(CarbonImmutable::parse('2026-08-10 23:00')));
        $this->assertFalse($this->sla->isWithinWindow(CarbonImmutable::parse('2026-08-10 06:59')));
    }
}
