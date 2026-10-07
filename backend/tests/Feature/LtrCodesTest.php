<?php

namespace Tests\Feature;

use App\Services\InventoryService;
use App\Support\Ltr;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * 0.3.0 regression: "(COIL-CLN)" after an Arabic part name wrapped with its
 * brackets on the wrong side. SKUs, serials and document numbers inside
 * Arabic text are isolated as left-to-right (<bdi dir="ltr">; in an <option>,
 * which cannot hold markup, with LRI…PDI).
 */
class LtrCodesTest extends DarakTestCase
{
    public function test_panel_visit_parts_isolate_the_sku(): void
    {
        $this->part->forceFill(['sku' => 'COIL-CLN', 'name' => 'منظف ملفات قلوي'])->save();
        $inventory = app(InventoryService::class);
        $inventory->receipt((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id);
        $inventory->loadVehicle((string) Str::uuid(), $this->part->id, 5, $this->warehouse->id, $this->vehicleStock->id);
        $inventory->issueToVisit((string) Str::uuid(), $this->part->id, 1, $this->vehicleStock->id, $this->visit);

        $this->actingAs($this->owner, 'web')->get(route('panel.visit', $this->visit))->assertOk()
            ->assertSee('منظف ملفات قلوي <span style="color:var(--muted);font-size:12px">(<bdi dir="ltr" style="white-space:nowrap">COIL-CLN</bdi>)</span>', false)
            ->assertSee('أمر العمل <bdi dir="ltr" style="white-space:nowrap">', false);
    }

    public function test_inventory_options_isolate_the_sku_without_markup(): void
    {
        $this->part->forceFill(['sku' => 'CAP-35-440'])->save();

        $this->actingAs($this->owner, 'web')->get(route('panel.inventory'))->assertOk()
            ->assertSee("(\u{2066}CAP-35-440\u{2069})", false)
            ->assertDontSee('<option value="'.$this->part->id.'"><bdi', false);
    }

    public function test_plain_text_isolation(): void
    {
        $this->assertSame("\u{2066}SN-1\u{2069}", Ltr::text('SN-1'));
        $this->assertSame('', Ltr::text(null));
    }
}
