<?php

namespace Tests\Feature;

use Tests\DarakTestCase;

class OperationsTabsTest extends DarakTestCase
{
    public function test_operations_sections_are_loaded_as_separate_tabs(): void
    {
        $this->actingAs($this->owner, 'web');

        $this->get(route('panel.operations', ['tab' => 'schedule']))
            ->assertOk()
            ->assertSee('تقويم الفنيين الأسبوعي')
            ->assertDontSee('طلبات المواعيد من العملاء');

        $this->get(route('panel.operations', ['tab' => 'requests']))
            ->assertOk()
            ->assertSee('طلبات المواعيد من العملاء')
            ->assertDontSee('تقويم الفنيين الأسبوعي');

        $this->get(route('panel.operations', ['tab' => 'quality']))
            ->assertOk()
            ->assertSee('اعتراضات رسمية على تقارير الزيارات')
            ->assertDontSee('غياب الفني وإعادة التوزيع');

        $this->get(route('panel.operations', ['tab' => 'resources']))
            ->assertOk()
            ->assertSee('غياب الفني وإعادة التوزيع')
            ->assertDontSee('اعتراضات رسمية على تقارير الزيارات');
    }

    public function test_unknown_operations_tab_falls_back_to_schedule(): void
    {
        $this->actingAs($this->owner, 'web')
            ->get(route('panel.operations', ['tab' => 'unknown']))
            ->assertOk()
            ->assertSee('تقويم الفنيين الأسبوعي');
    }
}
