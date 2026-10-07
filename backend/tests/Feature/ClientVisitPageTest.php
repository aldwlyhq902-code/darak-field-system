<?php

namespace Tests\Feature;

use App\Models\ClientPortalUser;
use App\Models\Visit;
use App\Models\VisitFeedback;
use Tests\DarakTestCase;

class ClientVisitPageTest extends DarakTestCase
{
    private ClientPortalUser $portal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->portal = ClientPortalUser::create([
            'client_id' => $this->client->id, 'name' => 'مدير الفرع', 'email' => 'visit-page@test.local',
            'password' => 'Strong-Client9!', 'is_active' => true,
        ]);
    }

    /**
     * 0.3.0 regression: a lone "." under "5/5" in the rating card. The portal
     * layout made every <b> in a card a big block (meant for a stat card's
     * figure), so the sentence's closing period wrapped onto its own line.
     */
    public function test_the_rating_sentence_stays_on_one_line(): void
    {
        $this->visit->forceFill(['state' => Visit::STATE_COMPLETED, 'closed_at' => now()])->save();
        VisitFeedback::create(['visit_id' => $this->visit->id, 'client_portal_user_id' => $this->portal->id, 'rating' => 5, 'resolution_confirmed' => true]);

        $html = $this->actingAs($this->portal, 'client')->get(route('client.visit', $this->visit))->assertOk()
            ->assertSee('<p>تم استلام تقييمك: <b>5/5</b>.</p>', false)
            ->getContent();

        // Only a card's own figure (its direct <b>) is a block.
        $this->assertStringContainsString('.card>b{display:block', $html);
        $this->assertDoesNotMatchRegularExpression('/\.card b\{display:block/', $html);
    }
}
