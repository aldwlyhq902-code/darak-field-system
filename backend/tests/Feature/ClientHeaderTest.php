<?php

namespace Tests\Feature;

use App\Models\ClientPortalUser;
use Tests\DarakTestCase;

/**
 * 0.3.0 regression: the client portal's sticky header was translucent (page
 * text scrolled visibly through it), the «دارك» brand was dark teal on the
 * dark header, and on a phone the fixed language pill sat over the header's
 * end. Checked at 390x844 in a browser; here the rules that ensure it.
 */
class ClientHeaderTest extends DarakTestCase
{
    public function test_the_header_is_opaque_with_a_readable_brand_and_room_for_the_language_pill(): void
    {
        $portal = ClientPortalUser::create([
            'client_id' => $this->client->id, 'name' => 'مدير الفرع', 'email' => 'header@test.local',
            'password' => 'Strong-Client9!', 'is_active' => true,
        ]);

        $html = $this->actingAs($portal, 'client')->get(route('client.home'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/\.top\{position:sticky;top:0;z-index:20;background:var\(--dark\);/', $html);
        $this->assertDoesNotMatchRegularExpression('/\.top\{[^}]*background:rgba/', $html, 'no see-through header');
        $this->assertMatchesRegularExpression('/\.logo\{[^}]*color:#fff/', $html);
        // The pill is fixed at the inline end (76px wide + 10px offset).
        $this->assertStringContainsString('.top .bar{padding-inline-end:92px}', $html);
        $this->assertStringContainsString('<a class="logo"', $html);
    }
}
