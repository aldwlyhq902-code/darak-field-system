<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\NotificationMessage;
use App\Models\OperatingCompany;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\DarakTestCase;

/**
 * Review finding M6: any new device_uuid used to receive a 30-day token. Now a
 * technician's first active handset self-registers and every further one waits
 * for the owner.
 */
class DeviceApprovalTest extends DarakTestCase
{
    public function test_first_device_of_a_technician_is_approved_automatically(): void
    {
        $uuid = (string) Str::uuid();

        $this->login('tech2@test.local', $uuid)->assertOk()->assertJsonStructure(['token']);

        $device = Device::where('device_uuid', $uuid)->sole();
        $this->assertTrue($device->isApproved());
        $this->assertNotNull($device->approved_at);
    }

    public function test_a_second_device_waits_for_approval_without_a_token(): void
    {
        $uuid = (string) Str::uuid();

        $this->login('tech1@test.local', $uuid)
            ->assertForbidden()
            ->assertJsonPath('code', 'DEVICE_PENDING_APPROVAL')
            ->assertJsonMissingPath('token');
        // Retrying does not mint a token or spam the owner.
        $this->login('tech1@test.local', $uuid)->assertJsonPath('code', 'DEVICE_PENDING_APPROVAL');

        $device = Device::where('device_uuid', $uuid)->sole();
        $this->assertTrue($device->isPendingApproval());
        $this->assertSame(0, PersonalAccessToken::where('name', 'device:'.$uuid)->count());

        $alert = NotificationMessage::where('type', NotificationMessage::TYPE_SECURITY_ALERT)->sole();
        $this->assertSame('device_pending_approval', $alert->context['reason']);
        $this->assertSame($device->id, $alert->context['device_id']);

        // It lands in the owner's inbox (scoped to the technician's company).
        $this->actingAs($this->owner, 'web')
            ->get(route('panel.notifications'))
            ->assertOk()
            ->assertSee('جهاز جديد بانتظار الاعتماد');
    }

    public function test_after_owner_approval_the_next_login_succeeds(): void
    {
        $uuid = (string) Str::uuid();
        $this->login('tech1@test.local', $uuid)->assertForbidden();
        $device = Device::where('device_uuid', $uuid)->sole();

        $this->actingAs($this->owner, 'web')
            ->get(route('panel.team'))
            ->assertOk()
            ->assertSee('بانتظار الاعتماد')
            ->assertSee(route('panel.team.device.approve', $device), false);

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.team.device.approve', $device))
            ->assertRedirect();

        $this->assertSame($this->owner->id, $device->refresh()->approved_by);
        $this->login('tech1@test.local', $uuid)->assertOk()->assertJsonStructure(['token']);
    }

    public function test_a_rejected_device_is_revoked(): void
    {
        $uuid = (string) Str::uuid();
        $this->login('tech1@test.local', $uuid)->assertForbidden();
        $device = Device::where('device_uuid', $uuid)->sole();

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.team.device.reject', $device))
            ->assertRedirect();

        $this->login('tech1@test.local', $uuid)->assertForbidden()->assertJsonPath('code', 'DEVICE_REVOKED');
    }

    public function test_replacement_after_revoking_the_only_device_registers_itself(): void
    {
        $this->device->forceFill(['revoked_at' => now(), 'revoked_reason' => 'lost'])->save();

        $this->login('tech1@test.local', (string) Str::uuid())->assertOk()->assertJsonStructure(['token']);
    }

    public function test_only_owner_or_team_managers_decide_and_only_inside_their_company(): void
    {
        $pending = Device::create(['user_id' => $this->technician->id, 'device_uuid' => (string) Str::uuid()]);
        $pending->forceFill(['approval_status' => Device::APPROVAL_PENDING])->save();

        $salesOnly = $this->panelUser('sales@test.local', ['sales'], $this->operatingCompany->id);
        $this->actingAs($salesOnly, 'web')
            ->post(route('panel.team.device.approve', $pending))
            ->assertForbidden();

        $teamManager = $this->panelUser('team@test.local', ['team'], $this->operatingCompany->id);
        $this->actingAs($teamManager, 'web')
            ->post(route('panel.team.device.approve', $pending))
            ->assertRedirect();
        $this->assertTrue($pending->refresh()->isApproved());

        $otherCompany = OperatingCompany::create(['name' => 'Other Co', 'is_active' => true]);
        $outsider = $this->panelUser('outsider@test.local', ['team'], $otherCompany->id);
        $foreign = Device::create(['user_id' => $this->technician->id, 'device_uuid' => (string) Str::uuid()]);
        $foreign->forceFill(['approval_status' => Device::APPROVAL_PENDING])->save();

        $this->actingAs($outsider, 'web')
            ->post(route('panel.team.device.approve', $foreign))
            ->assertNotFound();
        $this->assertTrue($foreign->refresh()->isPendingApproval());
    }

    public function test_backoffice_accounts_rely_on_mfa_not_device_approval(): void
    {
        Device::create(['user_id' => $this->owner->id, 'device_uuid' => (string) Str::uuid()]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'secret',
            'device_uuid' => (string) Str::uuid(),
            'mfa_code' => app(TwoFactorService::class)->currentCode($this->owner),
        ])->assertOk()->assertJsonStructure(['token']);
    }

    // --- idle expiry (C1: 401 means "log in again, keep the queue") ----------

    public function test_a_token_unused_for_longer_than_the_idle_window_is_rejected(): void
    {
        $token = $this->login('tech1@test.local', $this->device->device_uuid)->json('token');
        PersonalAccessToken::query()->update(['last_used_at' => now()->subDays(15)]);

        $this->asToken($token)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_a_recently_used_token_keeps_working(): void
    {
        $token = $this->login('tech1@test.local', $this->device->device_uuid)->json('token');
        PersonalAccessToken::query()->update(['last_used_at' => now()->subDays(13)]);

        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    // --- C4 integrity, C7 app version ----------------------------------------

    public function test_reported_integrity_is_stored_and_flagged_but_never_blocks(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'tech2@test.local',
            'password' => 'secret',
            'device_uuid' => $uuid,
            'device_integrity' => ['rooted' => true, 'emulator' => false],
        ])->assertOk();

        $device = Device::where('device_uuid', $uuid)->sole();
        $this->assertTrue($device->integrity['rooted']);
        $this->assertFalse($device->integrity['emulator']);
        $this->assertTrue($device->hasIntegrityWarning());

        $this->actingAs($this->owner, 'web')->get(route('panel.team'))->assertSee('جهاز مكسور الحماية');
    }

    public function test_app_version_header_updates_the_device_on_the_presence_write(): void
    {
        $token = $this->login('tech1@test.local', $this->device->device_uuid)->json('token');
        $this->device->refresh()->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

        $this->asToken($token)
            ->withHeader('X-App-Version', '1.4.2')
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertSame('1.4.2', $this->device->refresh()->app_version);
    }

    private function login(string $email, string $uuid)
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => 'secret', 'device_uuid' => $uuid,
        ]);
    }

    /** @param array<int, string> $permissions */
    private function panelUser(string $email, array $permissions, int $companyId): User
    {
        $user = User::create([
            'name' => $email, 'email' => $email, 'password' => Hash::make('Strong-Test9!'),
            'role' => User::ROLE_ADMIN, 'is_active' => true, 'permissions' => $permissions,
            'operating_company_id' => $companyId,
        ]);
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }
}
