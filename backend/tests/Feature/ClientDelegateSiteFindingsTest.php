<?php

namespace Tests\Feature;

use App\Models\ClientPortalUser;
use App\Models\Visit;
use App\Models\VisitFeedback;
use Illuminate\Support\Facades\Hash;
use Tests\DarakTestCase;

/** M4: a site-restricted client delegate cannot act on visits at other sites. */
class ClientDelegateSiteFindingsTest extends DarakTestCase
{
    public function test_site_restricted_delegate_cannot_rate_a_visit_at_another_site(): void
    {
        $this->visit->forceFill(['state' => Visit::STATE_COMPLETED, 'closed_at' => now()])->save();
        $otherSite = $this->client->sites()->create(['name' => 'Delegate Site']);
        $delegate = ClientPortalUser::create([
            'client_id' => $this->client->id, 'name' => 'Site Delegate', 'email' => 'site-delegate@test.local',
            'password' => Hash::make('Strong-Client9!'), 'is_active' => true,
        ]);
        $delegate->allowedSites()->attach($otherSite);

        $this->actingAs($delegate, 'client')
            ->post(route('client.visit.feedback', $this->visit), ['rating' => 1, 'resolution_confirmed' => 0, 'comment' => 'Not my site'])
            ->assertForbidden();

        $this->assertSame(0, VisitFeedback::withoutGlobalScopes()->count());
    }

    public function test_unrestricted_portal_user_still_rates_its_visits(): void
    {
        $this->visit->forceFill(['state' => Visit::STATE_COMPLETED, 'closed_at' => now()])->save();
        $portal = ClientPortalUser::create([
            'client_id' => $this->client->id, 'name' => 'Client Manager', 'email' => 'client-manager@test.local',
            'password' => Hash::make('Strong-Client9!'), 'is_active' => true,
        ]);

        $this->actingAs($portal, 'client')
            ->post(route('client.visit.feedback', $this->visit), ['rating' => 5, 'resolution_confirmed' => 1])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame(1, VisitFeedback::withoutGlobalScopes()->count());
    }
}
