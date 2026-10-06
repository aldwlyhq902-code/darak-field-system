<?php

namespace Tests\Feature;

use App\Models\ClientPortalUser;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\DarakTestCase;

/**
 * Every parameterless GET page an authorised person can open must render.
 * Branch merges and view edits break pages in ways no feature test notices
 * (a dropped import, a renamed variable in a one-line template); this catches
 * the 500 before a user does.
 */
class PageSmokeTest extends DarakTestCase
{
    public function test_every_owner_panel_page_renders(): void
    {
        $this->actingAs($this->owner, 'web');

        $this->assertPagesRender(fn (Route $route): bool => ! $this->isClientPortal($route) && ! $this->isPublic($route));
    }

    public function test_core_detail_pages_render(): void
    {
        $this->actingAs($this->owner, 'web');

        $this->get(route('panel.client', $this->client))->assertOk();
        $this->get(route('panel.visit', $this->visit))->assertOk();
        $this->get(route('panel.site.emergency-sticker', $this->site))->assertOk();
    }

    public function test_every_client_portal_page_renders(): void
    {
        $portalUser = ClientPortalUser::create([
            'client_id' => $this->client->id, 'name' => 'Client Manager',
            'email' => 'smoke-client@test.local', 'password' => 'Strong-Client9!', 'is_active' => true,
        ]);
        $this->actingAs($portalUser, 'client');

        $this->assertPagesRender(fn (Route $route): bool => $this->isClientPortal($route));
        $this->get(route('client.visit', $this->visit))->assertOk();
        $this->get(route('client.contract', $this->contract))->assertOk();
    }

    /** @param  callable(Route): bool  $include */
    private function assertPagesRender(callable $include): void
    {
        $checked = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || $route->parameterNames() !== []) {
                continue;
            }
            $uri = $route->uri();
            if (str_starts_with($uri, 'api/') || str_starts_with($uri, '_') || str_contains($uri, 'logout')
                || str_ends_with($uri, '.csv') || str_ends_with($uri, '.pdf') || str_contains($uri, 'export')
                || ! in_array('web', $route->gatherMiddleware(), true) || ! $include($route)) {
                continue;
            }

            $status = $this->get('/'.ltrim($uri, '/'))->getStatusCode();
            $checked[] = $uri;
            $this->assertLessThan(500, $status, "GET /{$uri} returned {$status}");
        }

        $this->assertNotEmpty($checked, 'no pages were checked');
    }

    private function isClientPortal(Route $route): bool
    {
        return str_starts_with($route->uri(), 'client');
    }

    private function isPublic(Route $route): bool
    {
        $uri = $route->uri();

        return in_array($uri, ['up', '/'], true) || str_starts_with($uri, 'report') || str_contains($uri, 'password')
            || str_contains($uri, 'login') || str_contains($uri, 'two-factor/challenge');
    }
}
