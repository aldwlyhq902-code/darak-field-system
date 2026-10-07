<?php

namespace Tests\Feature;

use App\Models\MaintenancePlan;
use App\Support\Ltr;
use Tests\DarakTestCase;

/**
 * 0.3.0 regression: "آخر توليد: 2026-10-07" displayed as 07-10-2026 beside
 * YYYY-MM-DD columns. The format was Y-m-d all along; digits after Arabic
 * letters are Arabic numbers to the bidi algorithm, and hyphens do not join
 * those, so the parts were laid out right to left. Dates inside Arabic text
 * are now isolated, and one format (Y-m-d) is used throughout.
 */
class DateDisplayTest extends DarakTestCase
{
    public function test_maintenance_plan_dates_read_the_same_in_every_column(): void
    {
        MaintenancePlan::create([
            'client_id' => $this->client->id, 'site_id' => $this->site->id, 'title' => 'صيانة شهرية',
            'frequency_days' => 30, 'duration_minutes' => 90, 'next_due_on' => '2026-11-06',
            'last_generated_on' => '2026-10-07', 'is_active' => true, 'created_by' => $this->owner->id,
        ]);

        $this->actingAs($this->owner, 'web')->get(route('panel.maintenance'))->assertOk()
            ->assertSee('2026-11-06')
            ->assertSee('آخر توليد: <bdi dir="ltr">2026-10-07</bdi>', false);
    }

    public function test_dates_inside_arabic_text_are_isolated_in_the_visit_report(): void
    {
        $template = file_get_contents(resource_path('views/reports/visit.blade.php'));

        $this->assertStringContainsString("وقّع بتاريخ {{ \App\Support\Ltr::wrap(\$signature->captured_at?->format('Y-m-d H:i') ?? '—') }}", $template);
        $this->assertStringContainsString("تاريخ الإصدار: {{ \App\Support\Ltr::wrap(now()->format('Y-m-d H:i')) }}", $template);
    }

    public function test_one_date_format_across_the_views(): void
    {
        $offenders = [];
        foreach (glob(resource_path('views/{,*/,*/*/}*.blade.php'), GLOB_BRACE) as $file) {
            // Day-first or slash formats next to Y-m-d elsewhere. (Long textual
            // dates such as translatedFormat('l، j F Y') and the compact
            // calendar chip are deliberate and not matched.)
            if (preg_match_all("/->format\('(d-m-Y|d\/m\/Y|Y\/m\/d|m-d H:i|d-m-Y H:i)'\)/", (string) file_get_contents($file), $m)) {
                $offenders[] = basename($file).': '.implode(', ', $m[1]);
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_isolation_helper_escapes_and_skips_empty_values(): void
    {
        $this->assertSame('<bdi dir="ltr">2026-10-07</bdi>', (string) Ltr::wrap('2026-10-07'));
        $this->assertSame('<bdi dir="ltr">&lt;b&gt;</bdi>', (string) Ltr::wrap('<b>'));
        $this->assertSame('', (string) Ltr::wrap(null));
    }
}
