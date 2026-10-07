<?php

namespace Tests\Feature;

use App\Models\SalesLead;
use App\Support\Labels;
use Tests\DarakTestCase;

/**
 * 0.3.0 regression: the team page's "MFA" header was English, and lead
 * sources such as "Google" were shown raw. Known sources come from the label
 * map whatever their case; free text stays exactly as entered.
 */
class TeamAndSourceLabelsTest extends DarakTestCase
{
    public function test_the_team_page_header_is_arabic(): void
    {
        $this->actingAs($this->owner, 'web')->get(route('panel.team'))->assertOk()
            ->assertSee('<th style="width:120px">التحقق بخطوتين</th>', false)
            ->assertDontSee('>MFA<', false);
    }

    public function test_lead_sources_use_the_label_map_and_keep_free_text(): void
    {
        $this->assertSame('جوجل', Labels::of('lead_source', 'google'));
        $this->assertSame('جوجل', Labels::of('lead_source', 'Google'));
        $this->assertSame('توصية', Labels::of('lead_source', 'REFERRAL'));
        $this->assertSame('معرض الرياض للمطاعم', Labels::of('lead_source', 'معرض الرياض للمطاعم'));
        $this->assertSame('Instagram Ads', Labels::of('lead_source', 'Instagram Ads'));

        SalesLead::create(['lead_no' => 'LEAD-SRC-1', 'company_name' => 'مطعم من جوجل', 'stage' => 'qualified', 'source' => 'Google', 'estimated_value' => 1000, 'owner_user_id' => $this->owner->id, 'operating_branch_id' => $this->operatingBranch->id]);
        SalesLead::create(['lead_no' => 'LEAD-SRC-2', 'company_name' => 'مطعم من معرض', 'stage' => 'qualified', 'source' => 'معرض الرياض للمطاعم', 'estimated_value' => 1000, 'owner_user_id' => $this->owner->id, 'operating_branch_id' => $this->operatingBranch->id]);

        $html = $this->actingAs($this->owner, 'web')->get(route('sales.home'))->assertOk()
            ->assertSee('جوجل')
            ->assertSee('معرض الرياض للمطاعم')
            ->assertSee('<option value="google" >جوجل</option>', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/>\s*Google\s*</', $html);
    }
}
