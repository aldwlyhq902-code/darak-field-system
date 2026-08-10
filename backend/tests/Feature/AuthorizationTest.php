<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Acceptance criterion 6: authorisation is enforced at the API, not by hiding
 * buttons. Plus device revocation, which has no equivalent in the old spec but is
 * an ordinary event — technicians lose phones.
 */
class AuthorizationTest extends DarakTestCase
{
    public function test_technician_cannot_read_another_technicians_visit(): void
    {
        $otherVisit = $this->visitFor($this->otherTechnician);

        $this->actingAs($this->technician)
            ->getJson("/api/v1/visits/{$otherVisit->id}")
            ->assertStatus(403);
    }

    public function test_technician_cannot_transition_another_technicians_visit(): void
    {
        $otherVisit = $this->visitFor($this->otherTechnician);

        $this->actingAs($this->technician)
            ->postJson("/api/v1/visits/{$otherVisit->id}/transition", ['to' => Visit::STATE_EN_ROUTE])
            ->assertStatus(403);
    }

    public function test_visit_list_is_scoped_to_the_authenticated_technician(): void
    {
        $this->visitFor($this->otherTechnician);

        $response = $this->actingAs($this->technician)->getJson('/api/v1/visits')->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertSame([$this->visit->id], $ids);
    }

    public function test_owner_sees_every_visit(): void
    {
        $this->visitFor($this->otherTechnician);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/visits')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_technician_cannot_override_a_rework_flag(): void
    {
        $this->visit->forceFill(['is_rework' => true, 'rework_system_flagged' => true])->save();

        $this->actingAs($this->technician)
            ->postJson("/api/v1/visits/{$this->visit->id}/rework-override", [
                'reason' => 'client_request',
                'note' => 'trying to protect my first-time-fix number',
            ])
            ->assertStatus(403);

        $this->assertTrue($this->visit->refresh()->is_rework);
    }

    public function test_supervisor_may_override_a_rework_flag_and_it_is_recorded(): void
    {
        $this->visit->forceFill(['is_rework' => true, 'rework_system_flagged' => true, 'is_billable' => false])->save();

        $this->actingAs($this->owner)
            ->postJson("/api/v1/visits/{$this->visit->id}/rework-override", [
                'reason' => 'client_request',
                'note' => 'Client asked for a different unit; unrelated to the earlier fault.',
            ])
            ->assertOk();

        $visit = $this->visit->refresh();
        $this->assertFalse($visit->is_rework);
        $this->assertTrue($visit->is_billable);
        $this->assertSame($this->owner->id, $visit->rework_overridden_by);
        // The system flag itself is retained, so strict FTF is still computable.
        $this->assertTrue($visit->rework_system_flagged);
    }

    public function test_revoked_device_token_is_rejected_on_protected_endpoints(): void
    {
        // Entirely token-based: actingAs() would authenticate every later request
        // in this test and mask whether revocation actually took effect.
        $techToken = $this->loginToken('tech1@test.local');
        $ownerToken = $this->loginToken('owner@test.local');

        $deviceId = $this->deviceIdFor($techToken);

        $this->asToken($techToken)->getJson('/api/v1/auth/me')->assertOk();

        $this->asToken($ownerToken)
            ->postJson("/api/v1/devices/{$deviceId}/revoke", ['reason' => 'lost handset'])
            ->assertOk();

        $this->asToken($techToken)->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->assertNotNull(Device::find($deviceId)->revoked_at);
    }

    public function test_revoked_device_cannot_log_in_again(): void
    {
        $uuid = (string) Str::uuid();
        $ownerToken = $this->loginToken('owner@test.local');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'tech1@test.local', 'password' => 'secret', 'device_uuid' => $uuid,
        ])->assertOk();

        $this->asToken($ownerToken)
            ->postJson("/api/v1/devices/{$login->json('device.id')}/revoke")
            ->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'tech1@test.local', 'password' => 'secret', 'device_uuid' => $uuid,
        ])->assertStatus(403)->assertJsonPath('code', 'DEVICE_REVOKED');
    }

    /**
     * Regression guard. This app has no `login` route, so the default guest
     * redirect turned every unauthenticated API call into a 500 and the mobile
     * client had no way to know it simply needed to sign in again.
     */
    public function test_unauthenticated_api_call_returns_401_not_500(): void
    {
        $this->getJson("/api/v1/visits/{$this->visit->id}")
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_unauthenticated_call_without_json_headers_also_returns_401(): void
    {
        // curl with no Accept header is the exact case that produced the 500.
        $this->get("/api/v1/visits/{$this->visit->id}")->assertStatus(401);
    }

    private function loginToken(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'secret',
            'device_uuid' => (string) Str::uuid(),
        ])->assertOk()->json('token');
    }

    private function deviceIdFor(string $token): int
    {
        return Device::whereHas('user', fn ($q) => $q->where('email', 'tech1@test.local'))
            ->latest('id')->firstOrFail()->id;
    }

    private function visitFor($technician): Visit
    {
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-' . Str::random(6),
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'contract_id' => $this->contract->id,
            'type' => 'reactive',
            'title' => 'Other technician visit',
            'reported_at' => now(),
            'status' => 'scheduled',
        ]);

        return Visit::create([
            'work_order_id' => $workOrder->id,
            'site_id' => $this->site->id,
            'assigned_user_id' => $technician->id,
            'scheduled_start' => now()->addHours(4),
            'scheduled_end' => now()->addHours(6),
            'state' => Visit::STATE_SCHEDULED,
        ]);
    }
}
