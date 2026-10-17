<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSupportedAppVersion;
use App\Services\TwoFactorService;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Sideloaded APKs cannot be pulled from a store. DARAK_MIN_APP_VERSION is the
 * way to stop a faulty build: older technician phones get 426 and keep their
 * queue; everything else keeps working.
 */
class AppVersionGateTest extends DarakTestCase
{
    public function test_no_minimum_means_no_gate(): void
    {
        config(['darak.api.min_app_version' => null]);

        $this->asToken($this->techToken())->withHeader('X-App-Version', '0.0.1')
            ->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_an_older_build_is_told_to_update_on_every_endpoint(): void
    {
        $token = $this->techToken();
        config(['darak.api.min_app_version' => '0.3.1']);

        foreach (['/api/v1/auth/me', '/api/v1/sync/bootstrap'] as $path) {
            $this->asToken($token)->withHeader('X-App-Version', '0.3.0+3')->getJson($path)
                ->assertStatus(426)
                ->assertJsonPath('code', 'APP_UPDATE_REQUIRED')
                ->assertJsonPath('min_version', '0.3.1');
        }

        // A build from before the header existed is older than any minimum.
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertStatus(426);
    }

    public function test_the_minimum_build_and_newer_pass(): void
    {
        $token = $this->techToken();
        config(['darak.api.min_app_version' => '0.3.1+4']);

        foreach (['0.3.1+4', '0.3.1+5', '0.3.2', '1.0.0'] as $version) {
            $this->asToken($token)->withHeader('X-App-Version', $version)
                ->getJson('/api/v1/auth/me')->assertOk();
        }
        $this->asToken($token)->withHeader('X-App-Version', '0.3.1+3')
            ->getJson('/api/v1/auth/me')->assertStatus(426);
    }

    public function test_an_older_build_cannot_sign_in_either(): void
    {
        config(['darak.api.min_app_version' => '0.3.1']);

        $this->withHeader('X-App-Version', '0.3.0+3')->postJson('/api/v1/auth/login', [
            'email' => 'tech1@test.local',
            'password' => 'secret',
            'device_uuid' => $this->device->device_uuid,
        ])->assertStatus(426)->assertJsonPath('code', 'APP_UPDATE_REQUIRED');
    }

    public function test_a_revoked_phone_hears_wipe_before_update(): void
    {
        $token = $this->techToken();
        $this->device->forceFill(['revoked_at' => now()])->save();
        config(['darak.api.min_app_version' => '9.0.0']);

        $this->asToken($token)->withHeader('X-App-Version', '0.3.0')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(403)
            ->assertJsonPath('code', 'DEVICE_REVOKED');
    }

    public function test_back_office_api_clients_are_not_gated(): void
    {
        config(['darak.api.min_app_version' => '9.0.0']);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'secret',
            'device_uuid' => (string) Str::uuid(),
            'mfa_code' => app(TwoFactorService::class)->currentCode($this->owner),
        ])->assertOk()->json('token');

        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_versions_compare_numerically(): void
    {
        $this->assertSame(1, EnsureSupportedAppVersion::compare('0.10.0', '0.9.9'));
        $this->assertSame(0, EnsureSupportedAppVersion::compare('0.3.1', '0.3.1+0'));
        $this->assertSame(-1, EnsureSupportedAppVersion::compare('garbage', '0.0.1'));
    }

    private function techToken(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => 'tech1@test.local',
            'password' => 'secret',
            'device_uuid' => $this->device->device_uuid,
        ])->assertOk()->json('token');
    }
}
