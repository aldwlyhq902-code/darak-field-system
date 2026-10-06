<?php

namespace Tests\Feature;

use App\Models\ClientPortalUser;
use App\Models\Visit;
use App\Services\ApproximateTechnicianLocation;
use App\Services\TrafficRouteProvider;
use Illuminate\Support\Facades\Http;
use Tests\DarakTestCase;

class ClientLocationPrivacyTest extends DarakTestCase
{
    private ClientPortalUser $portal;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'darak.map_tiles_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'darak.map_attribution' => '© OpenStreetMap contributors',
        ]);
        $this->portal = ClientPortalUser::create([
            'client_id' => $this->client->id, 'name' => 'Client Manager',
            'email' => 'client-location@test.local', 'password' => 'Strong-Client9!', 'is_active' => true,
        ]);
        $this->visit->forceFill([
            'state' => Visit::STATE_EN_ROUTE,
            'technician_lat' => 21.5812345,
            'technician_lng' => 39.1656789,
            'location_updated_at' => now()->subMinute(),
            'estimated_arrival_at' => now()->addMinutes(20),
        ])->save();
    }

    public function test_client_sees_only_an_approximate_position_while_en_route(): void
    {
        $response = $this->actingAs($this->portal, 'client')->get(route('client.visit', $this->visit))->assertOk();

        $response->assertSee('موقع الفني التقريبي')
            ->assertSee('https://tile.openstreetmap.org/15/', false)
            ->assertSee('© OpenStreetMap contributors')
            ->assertDontSee('21.5812345')
            ->assertDontSee('39.1656789')
            ->assertDontSee('openstreetmap.org/export/embed', false);

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("img-src 'self' data: https://tile.openstreetmap.org;", $policy);
        $this->assertStringContainsString("frame-src 'none'", $policy);
    }

    public function test_position_is_rounded_to_about_one_hundred_metres(): void
    {
        $location = app(ApproximateTechnicianLocation::class)->forClient($this->visit->fresh());

        $this->assertSame(21.581, $location['lat']);
        $this->assertSame(39.166, $location['lng']);
        $this->assertCount(9, $location['tiles']);
        $this->assertGreaterThan(33.3, $location['marker_left']);
        $this->assertLessThan(66.7, $location['marker_left']);
    }

    public function test_location_disappears_once_the_technician_arrives(): void
    {
        $this->visit->forceFill(['state' => Visit::STATE_STARTED])->save();

        $this->actingAs($this->portal, 'client')->get(route('client.visit', $this->visit))
            ->assertOk()
            ->assertDontSee('موقع الفني التقريبي')
            ->assertDontSee('tile.openstreetmap.org', false);
        $this->assertNull(app(ApproximateTechnicianLocation::class)->forClient($this->visit->fresh()));
    }

    public function test_tiles_none_shows_a_text_eta_and_makes_no_third_party_request(): void
    {
        config(['darak.map_tiles_url' => 'none']);

        $response = $this->actingAs($this->portal, 'client')->get(route('client.visit', $this->visit))->assertOk();

        $response->assertSee('موقع الفني التقريبي')
            ->assertSee('الوصول المتوقع')
            ->assertDontSee('<img', false)
            ->assertDontSee('openstreetmap', false);
        $this->assertStringContainsString("img-src 'self' data:;", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_unsafe_tile_templates_are_treated_as_none(): void
    {
        foreach (['http://tiles.example.test/{z}/{x}/{y}.png', 'https://tiles.example.test/static.png', ''] as $template) {
            config(['darak.map_tiles_url' => $template]);
            $this->assertNull(ApproximateTechnicianLocation::tileTemplate(), $template);
        }

        config(['darak.map_tiles_url' => 'https://{s}.tiles.example.sa:8443/osm/{z}/{x}/{y}.png']);
        $this->assertSame('https://a.tiles.example.sa:8443', ApproximateTechnicianLocation::tileOrigin());
    }

    public function test_mapbox_receives_the_technician_position_rounded(): void
    {
        config(['darak.routing.provider' => 'mapbox', 'darak.routing.mapbox_token' => 'pk.test']);
        Http::fake(['api.mapbox.com/*' => Http::response(['routes' => [['duration' => 600, 'distance' => 4200]]])]);

        $route = app(TrafficRouteProvider::class)->estimate(21.5812345, 39.1656789, 21.5810, 39.1650);

        $this->assertSame(10, $route['minutes']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '39.166,21.581;')
            && ! str_contains($request->url(), '39.1656789'));
    }
}
