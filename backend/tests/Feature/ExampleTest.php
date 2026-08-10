<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Replaces Laravel's default "/ returns 200" test, which no longer describes this
 * application: the root is a panel entry point, so a guest is sent to the login
 * page rather than served a welcome view.
 */
class ExampleTest extends TestCase
{
    public function test_root_sends_a_guest_to_the_panel_login(): void
    {
        $this->get('/')->assertRedirect(route('panel.board'));
        $this->get('/board')->assertRedirect(route('panel.login'));
    }

    public function test_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}
