<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\EmergencyReport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\DarakTestCase;

class ClientPortalAndEmergencyReportTest extends DarakTestCase
{
    public function test_client_guest_is_redirected_to_client_login_instead_of_panel_login(): void
    {
        $this->get(route('client.home'))
            ->assertRedirect(route('client.login'));
    }

    public function test_any_worker_can_open_a_sites_qr_form_without_an_account(): void
    {
        $this->get(route('emergency.create', $this->site->emergency_qr_secret))
            ->assertOk()
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()')
            ->assertSee($this->client->name)
            ->assertSee($this->site->name)
            ->assertSee('لا تحتاج إلى حساب');
    }

    public function test_an_unknown_or_rotated_qr_token_is_not_usable(): void
    {
        $old = $this->site->emergency_qr_secret;
        $this->site->rotateEmergencyQr();
        $this->site->save();

        $this->get(route('emergency.create', $old))->assertNotFound();
        $this->get(route('emergency.create', 'unknown-token'))->assertNotFound();
    }

    public function test_qr_report_is_stored_for_triage_with_private_photo_hash(): void
    {
        Storage::fake('local');
        $photo = UploadedFile::fake()->image('fault.jpg', 900, 700);

        $response = $this->post(route('emergency.store', $this->site->emergency_qr_secret), [
            'reporter_name' => 'عامل المطبخ',
            'reporter_phone' => '0501234567',
            'reporter_role' => 'المطبخ',
            'category' => 'refrigeration',
            'severity' => 'critical',
            'description' => 'الفريزر توقف بالكامل ودرجة الحرارة ترتفع بسرعة.',
            'asset_id' => $this->asset->id,
            'photo' => $photo,
        ]);

        $report = EmergencyReport::firstOrFail();
        $response->assertRedirect(route('emergency.received', $report->public_reference));
        $this->assertSame(EmergencyReport::STATUS_NEW, $report->status);
        $this->assertNull($report->work_order_id, 'public reports must not schedule work before triage');
        $this->assertSame($this->site->id, $report->site_id);
        $this->assertSame(64, strlen((string) $report->photo_sha256));
        Storage::disk('local')->assertExists($report->photo_path);
        $this->assertDatabaseHas('notification_messages', [
            'type' => 'emergency.reported',
            'recipient_kind' => 'supervisor',
        ]);
    }

    public function test_public_report_rejects_an_asset_from_another_site(): void
    {
        $otherClient = Client::create(['name' => 'Other Client', 'is_active' => true]);
        $otherSite = $otherClient->sites()->create(['name' => 'Other Site', 'is_active' => true]);
        $otherAsset = $otherSite->assets()->create([
            'name' => 'Other Freezer', 'type' => 'freezer', 'qr_code' => 'OTHER-ASSET',
        ]);

        $this->post(route('emergency.store', $this->site->emergency_qr_secret), [
            'reporter_name' => 'Worker',
            'reporter_phone' => '0501234567',
            'category' => 'refrigeration',
            'severity' => 'urgent',
            'description' => 'A sufficiently detailed emergency description.',
            'asset_id' => $otherAsset->id,
        ])->assertSessionHasErrors('asset_id');

        $this->assertDatabaseCount('emergency_reports', 0);
    }

    public function test_honeypot_and_file_type_block_simple_abuse(): void
    {
        Storage::fake('local');
        $payload = [
            'reporter_name' => 'Worker',
            'reporter_phone' => '0501234567',
            'category' => 'ac',
            'severity' => 'urgent',
            'description' => 'The air conditioning stopped in the dining room.',
        ];

        $this->post(route('emergency.store', $this->site->emergency_qr_secret), $payload + [
            'company' => 'spam bot',
        ])->assertUnprocessable();

        $this->post(route('emergency.store', $this->site->emergency_qr_secret), $payload + [
            'photo' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors('photo');

        $this->assertDatabaseCount('emergency_reports', 0);
    }

    public function test_owner_can_convert_a_report_to_an_urgent_work_order(): void
    {
        $report = $this->report();

        $this->actingAs($this->owner, 'web')
            ->post(route('panel.emergency.convert', $report))
            ->assertRedirect();

        $report->refresh();
        $this->assertSame(EmergencyReport::STATUS_CONVERTED, $report->status);
        $this->assertNotNull($report->work_order_id);
        $this->assertSame('urgent', $report->workOrder->priority);
        $this->assertSame($this->site->id, $report->workOrder->site_id);
        $this->assertCount(1, $report->workOrder->visits);
        $this->assertNull($report->workOrder->visits->first()->assigned_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'emergency.converted']);
    }

    public function test_client_portal_is_strictly_scoped_to_its_client(): void
    {
        $portalUser = $this->portalUser($this->client);
        $otherClient = Client::create(['name' => 'Other Client', 'is_active' => true]);
        $otherSite = $otherClient->sites()->create(['name' => 'Other Site', 'is_active' => true]);
        $otherOrder = $otherSite->workOrders()->create([
            'wo_number' => 'WO-OTHER',
            'client_id' => $otherClient->id,
            'type' => 'reactive',
            'title' => 'Other private visit',
            'reported_at' => now(),
            'status' => 'open',
        ]);
        $otherVisit = $otherOrder->visits()->create([
            'site_id' => $otherSite->id,
            'state' => 'scheduled',
            'state_changed_at' => now(),
        ]);

        $this->actingAs($portalUser, 'client')->get(route('client.home'))
            ->assertOk()
            ->assertSee($this->client->name)
            ->assertDontSee('Other private visit');
        $this->actingAs($portalUser, 'client')->get(route('client.visit', $otherVisit))
            ->assertNotFound();
    }

    public function test_client_can_download_only_its_completed_visit_report(): void
    {
        $portalUser = $this->portalUser($this->client);
        $this->visit->forceFill(['state' => 'completed', 'closed_at' => now()])->save();

        $this->actingAs($portalUser, 'client')
            ->get(route('client.visit.report', $this->visit))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_manifest_service_worker_and_mobile_metadata_are_available(): void
    {
        $this->get(route('client.login'))
            ->assertOk()
            ->assertSee('viewport-fit=cover', false)
            ->assertSee(route('client.manifest'), false)
            ->assertSee("scope:'/client'", false);
        $this->get(route('client.manifest'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertSee('دارك للعملاء');
        $this->get(route('client.service-worker'))
            ->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/');
        $this->get(route('client.service-worker'))
            ->assertSee('network-only', false)
            ->assertDontSee('cache.put(event.request');
    }

    public function test_only_owner_can_manage_portal_accounts_and_qr_rotation(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'portal-admin@test.local',
            'password' => Hash::make('Strong-Test9!'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
            'operating_company_id' => $this->operatingCompany->id,
            'operating_branch_id' => $this->operatingBranch->id,
        ]);
        $admin->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($admin, 'web')->post(route('panel.site.emergency-qr.rotate', $this->site))
            ->assertForbidden();
        $this->actingAs($admin, 'web')->post(route('panel.client.portal-user', $this->client), [])
            ->assertForbidden();
        $this->actingAs($admin, 'web')->get(route('panel.site.emergency-sticker', $this->site))
            ->assertOk();
    }

    private function portalUser(Client $client): ClientPortalUser
    {
        return ClientPortalUser::create([
            'client_id' => $client->id,
            'name' => 'Client Manager',
            'email' => 'client@test.local',
            'password' => 'Strong-Client9!',
            'is_active' => true,
        ]);
    }

    private function report(): EmergencyReport
    {
        return EmergencyReport::create([
            'public_reference' => fake()->uuid(),
            'site_id' => $this->site->id,
            'reporter_name' => 'Worker',
            'reporter_phone' => '0501234567',
            'category' => 'ac',
            'severity' => 'urgent',
            'description' => 'The air conditioning stopped in the main dining room.',
            'status' => EmergencyReport::STATUS_NEW,
            'source' => 'site_qr',
        ]);
    }
}
