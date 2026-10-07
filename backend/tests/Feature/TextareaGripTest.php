<?php

namespace Tests\Feature;

use App\Models\AdditionalWorkApproval;
use App\Models\ClientPortalUser;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * 0.3.0 regression: a stray "." under the «ملاحظة القرار» textarea. It was the
 * textarea's resize grip, drawn in the block-end/inline-end corner (bottom
 * left in RTL), outside the field's 12px rounded border. On touch screens the
 * grip is gone (it cannot be dragged there anyway); with a mouse its corner
 * is square. Both portal and sales layouts.
 */
class TextareaGripTest extends DarakTestCase
{
    private const RULE = 'textarea{resize:vertical;border-end-end-radius:3px}@media (pointer:coarse){textarea{resize:none;border-end-end-radius:12px}}';

    public function test_client_portal_textareas(): void
    {
        $portal = ClientPortalUser::create([
            'client_id' => $this->client->id, 'name' => 'مدير الفرع', 'email' => 'grip@test.local',
            'password' => 'Strong-Client9!', 'is_active' => true,
        ]);
        $approval = AdditionalWorkApproval::create([
            'public_reference' => (string) Str::uuid(), 'visit_id' => $this->visit->id, 'client_id' => $this->client->id,
            'title' => 'عمل إضافي', 'description' => 'وصف', 'items' => [], 'amount' => 100, 'vat_amount' => 15,
            'total_amount' => 115, 'status' => 'pending', 'sent_at' => now(), 'created_by' => $this->owner->id,
        ]);

        $this->actingAs($portal, 'client')->get(route('client.additional-work', $approval))->assertOk()
            ->assertSee('<textarea name="response_note"></textarea>', false)
            ->assertSee(self::RULE, false);
    }

    public function test_sales_app_textareas(): void
    {
        $this->actingAs($this->owner, 'web')->get(route('sales.home'))->assertOk()
            ->assertSee(self::RULE, false);
    }
}
