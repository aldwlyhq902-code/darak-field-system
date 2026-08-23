<?php

namespace Tests\Feature;

use Tests\DarakTestCase;

class SecurityHeadersTest extends DarakTestCase
{
    public function test_browser_responses_receive_security_headers(): void
    {
        $response = $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('Content-Security-Policy');

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' 'nonce-", $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
    }

    public function test_https_responses_enable_hsts(): void
    {
        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_http_responses_do_not_claim_hsts(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_login_controls_have_accessible_names_and_password_autocomplete(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('for="panel-email"', false)
            ->assertSee('id="panel-email"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('for="panel-password"', false)
            ->assertSee('autocomplete="current-password"', false);
    }

    public function test_performance_scripts_carry_the_nonce_declared_by_csp(): void
    {
        $response = $this->actingAs($this->owner, 'web')->get(route('panel.performance'))->assertOk();
        $policy = (string) $response->headers->get('Content-Security-Policy');
        preg_match("/nonce-([^']+)/", $policy, $matches);

        $this->assertNotEmpty($matches[1] ?? null);
        $this->assertStringContainsString('script nonce="'.$matches[1].'"', $response->getContent());
    }
}
