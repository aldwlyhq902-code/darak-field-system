<?php

namespace Tests\Feature;

use App\Services\AccountSecurity;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\DarakTestCase;

/**
 * The phone wipes itself on 403 DEVICE_REVOKED / USER_INACTIVE and keeps its
 * work on 401. Revocation deletes the token, so before tombstones the phone only
 * ever saw 401 and the remote wipe never ran. These pin the signal per path.
 */
class RemoteWipeSignalTest extends DarakTestCase
{
    public function test_a_device_revoked_from_the_panel_hears_device_revoked_on_every_endpoint(): void
    {
        $token = $this->techToken();

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.team.revoke', $this->device), ['reason' => 'هاتف مفقود'])
            ->assertSessionHas('ok');

        $this->assertSame(0, PersonalAccessToken::count(), 'the token itself must still be deleted');

        foreach (['/api/v1/auth/me', '/api/v1/sync/bootstrap', '/api/v1/visits'] as $path) {
            $this->asToken($token)->getJson($path)
                ->assertStatus(403)
                ->assertJsonPath('code', 'DEVICE_REVOKED');
        }

        $this->asToken($token)->postJson('/api/v1/sync/events', ['device_uuid' => $this->device->device_uuid, 'events' => []])
            ->assertStatus(403)
            ->assertJsonPath('code', 'DEVICE_REVOKED');
    }

    public function test_a_rejected_device_hears_device_revoked(): void
    {
        $token = $this->techToken();

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.team.device.reject', $this->device))
            ->assertRedirect();

        $this->asToken($token)->getJson('/api/v1/auth/me')
            ->assertStatus(403)
            ->assertJsonPath('code', 'DEVICE_REVOKED');
    }

    public function test_a_reactivated_account_does_not_wipe_a_phone_that_never_signed_out(): void
    {
        $token = $this->techToken();

        $this->actingAs($this->owner, 'web')->post(route('panel.team.toggle', $this->technician));
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertJsonPath('code', 'USER_INACTIVE');

        $this->actingAs($this->owner, 'web')->post(route('panel.team.toggle', $this->technician));

        // Back to an ordinary dead session: sign in again, keep the queue.
        $this->asToken($token)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_a_password_change_signs_the_phone_out_without_wiping_it(): void
    {
        $token = $this->techToken();

        app(AccountSecurity::class)->setPanelPassword($this->technician, 'a-new-password-1234', null, 'changed');

        $this->asToken($token)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_an_unknown_or_malformed_token_is_a_plain_401(): void
    {
        foreach (['999|'.Str::random(40), 'not-a-token', ''] as $bearer) {
            $this->asToken($bearer)->getJson('/api/v1/auth/me')
                ->assertUnauthorized()
                ->assertJsonPath('code', 'UNAUTHENTICATED');
        }
    }

    private function techToken(): string
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'tech1@test.local',
            'password' => 'secret',
            'device_uuid' => $this->device->device_uuid,
        ])->assertOk()->json('token');

        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk();

        return $token;
    }
}
