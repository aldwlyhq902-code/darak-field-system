<?php

namespace Tests\Feature;

use App\Models\NotificationMessage;
use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\Quotation;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\DarakTestCase;

class SalesPwaTest extends DarakTestCase
{
    private User $marketer;

    private OperatingBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $company = OperatingCompany::create(['name' => 'شركة المبيعات', 'currency' => 'SAR', 'is_active' => true]);
        $this->branch = OperatingBranch::create(['operating_company_id' => $company->id, 'name' => 'فرع المبيعات', 'code' => 'SALES', 'is_active' => true]);
        $this->owner->forceFill(['operating_company_id' => $company->id, 'operating_branch_id' => $this->branch->id])->save();
        $this->client->forceFill(['operating_company_id' => $company->id, 'operating_branch_id' => $this->branch->id])->save();
        $this->marketer = $this->panelUser('marketer@test.local', ['sales'], $this->branch->id);
    }

    public function test_sales_pwa_is_installable_and_sensitive_pages_are_network_only(): void
    {
        $this->get(route('sales.manifest'))->assertOk()
            ->assertHeader('content-type', 'application/manifest+json')
            ->assertSee('"display":"standalone"', false)
            ->assertSee('"start_url":"/sales/app"', false);
        $this->get(route('sales.service-worker'))->assertOk()
            ->assertSee('network-only')->assertSee('Cache Storage');
        $this->get(route('sales.offline'))->assertOk()->assertSee('لا نخزن لوحة المبيعات');
    }

    public function test_marketer_has_a_mobile_dashboard_and_only_sees_owned_pipeline(): void
    {
        SalesLead::create(['lead_no' => 'LEAD-MINE', 'company_name' => 'فرصتي الخاصة', 'stage' => 'qualified', 'estimated_value' => 25000, 'owner_user_id' => $this->marketer->id]);
        $other = $this->panelUser('other-marketer@test.local', ['sales'], $this->branch->id);
        SalesLead::create(['lead_no' => 'LEAD-OTHER', 'company_name' => 'فرصة مسوق آخر', 'stage' => 'new', 'estimated_value' => 10000, 'owner_user_id' => $other->id]);

        $this->actingAs($this->marketer, 'web')->get(route('sales.home'))
            ->assertOk()->assertSee('مسار المبيعات')->assertSee('فرصتي الخاصة')->assertDontSee('فرصة مسوق آخر')
            ->assertSee('تثبيت تطبيق المسوق')->assertSee('العمولة المستحقة');
    }

    public function test_marketer_can_create_follow_up_and_send_a_quotation(): void
    {
        $this->actingAs($this->marketer, 'web')->post(route('sales.leads.store'), [
            'company_name' => 'عميل محتمل جديد', 'contact_name' => 'مدير الفرع',
            'phone' => '0555555555', 'estimated_value' => 42000,
            'source' => 'campaign', 'campaign_name' => 'حملة المطاعم',
            'expected_close_on' => now()->addMonth()->toDateString(),
            'next_action_on' => now()->addDay()->toDateString(),
        ])->assertRedirect()->assertSessionHas('ok');
        $lead = SalesLead::where('company_name', 'عميل محتمل جديد')->firstOrFail();
        $this->assertSame($this->marketer->id, $lead->owner_user_id);
        $this->assertSame($this->branch->id, $lead->operating_branch_id);
        $this->assertSame('campaign', $lead->source);

        $this->post(route('sales.leads.activity', $lead), [
            'type' => 'call', 'note' => 'تم تحديد اجتماع العرض',
            'occurred_at' => now()->format('Y-m-d H:i:s'), 'stage' => 'qualified',
            'next_action_on' => now()->addDays(2)->toDateString(),
        ])->assertRedirect()->assertSessionHas('ok');
        $this->assertDatabaseHas('sales_activities', ['sales_lead_id' => $lead->id, 'user_id' => $this->marketer->id]);

        $this->post(route('sales.quotations.store'), [
            'client_id' => $this->client->id, 'site_ids' => [$this->site->id],
            'title' => 'عرض صيانة سنوي', 'package_code' => 'basic', 'price_amount' => 48000,
            'billing_cycle' => 'monthly', 'duration_months' => 12,
            'starts_on' => now()->addWeek()->toDateString(), 'valid_until' => now()->addDays(14)->toDateString(),
            'service_window_start' => '07:00', 'service_window_end' => '23:00', 'sla_minutes' => 240,
        ])->assertRedirect()->assertSessionHas('ok');
        $quotation = Quotation::where('created_by', $this->marketer->id)->firstOrFail();
        $this->post(route('sales.quotations.send', $quotation))->assertRedirect()->assertSessionHas('ok');
        $this->assertSame(Quotation::STATUS_SENT, $quotation->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'quotation.sent', 'user_id' => $this->marketer->id]);
    }

    public function test_lead_converts_to_client_site_and_contact_without_retyping(): void
    {
        $lead = SalesLead::create([
            'lead_no' => 'LEAD-CONVERT', 'company_name' => 'مطعم التحويل', 'contact_name' => 'مدير المطعم',
            'phone' => '0551234567', 'email' => 'convert@example.test', 'stage' => 'negotiation',
            'estimated_value' => 90000, 'owner_user_id' => $this->marketer->id,
        ]);

        $this->actingAs($this->marketer, 'web')->post(route('sales.leads.convert', $lead), [
            'category' => 'restaurant', 'payment_term' => 'quarterly_advance',
            'site_name' => 'فرع الروضة', 'site_address' => 'شارع الاختبار', 'city' => 'جدة', 'position' => 'المدير العام',
        ])->assertRedirect()->assertSessionHas('ok');

        $clientId = $lead->fresh()->converted_client_id;
        $this->assertNotNull($clientId);
        $this->assertDatabaseHas('clients', ['id' => $clientId, 'name' => 'مطعم التحويل', 'operating_branch_id' => $this->branch->id]);
        $this->assertDatabaseHas('sites', ['client_id' => $clientId, 'name' => 'فرع الروضة']);
        $this->assertDatabaseHas('contacts', ['client_id' => $clientId, 'phone' => '0551234567', 'can_approve' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sales.lead_converted']);
    }

    public function test_private_attachments_targets_and_discount_approval_workflow(): void
    {
        Storage::fake('local');
        $lead = SalesLead::create(['lead_no' => 'LEAD-FILES', 'company_name' => 'عميل الملفات', 'stage' => 'qualified', 'estimated_value' => 15000, 'owner_user_id' => $this->marketer->id]);
        $this->actingAs($this->marketer, 'web')->post(route('sales.leads.attachment', $lead), [
            'file' => UploadedFile::fake()->create('meeting-note.m4a', 300, 'audio/mp4'),
        ])->assertRedirect()->assertSessionHas('ok');
        $attachment = $lead->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
        $this->get(route('sales.attachments.download', $attachment))->assertOk()->assertHeader('cache-control', 'no-store, private');

        $this->actingAs($this->owner, 'web')->post(route('sales.targets.store'), [
            'user_id' => $this->marketer->id, 'month' => now()->format('Y-m'),
            'calls_target' => 50, 'meetings_target' => 15, 'proposals_target' => 10,
            'won_value_target' => 200000, 'collections_target' => 150000,
        ])->assertRedirect()->assertSessionHas('ok');
        $this->assertDatabaseHas('sales_targets', ['user_id' => $this->marketer->id, 'calls_target' => 50]);

        $quote = Quotation::create([
            'series_uuid' => fake()->uuid(), 'version' => 1, 'quote_no' => 'QT-DISCOUNT-V1',
            'client_id' => $this->client->id, 'title' => 'عرض خصم', 'package_code' => 'basic',
            'price_amount' => 50000, 'vat_rate' => .15, 'billing_cycle' => 'monthly', 'duration_months' => 12,
            'starts_on' => now(), 'valid_until' => now()->addMonth(), 'service_window_start' => '07:00',
            'service_window_end' => '23:00', 'sla_minutes' => 240, 'status' => Quotation::STATUS_DRAFT,
            'created_by' => $this->marketer->id,
        ]);
        $this->actingAs($this->marketer, 'web')->post(route('sales.quotations.discount', $quote), ['amount' => 2500, 'reason' => 'صفقة متعددة الفروع'])
            ->assertRedirect()->assertSessionHas('ok');
        $this->assertDatabaseHas('financial_approvals', ['subject_type' => 'quotation', 'subject_id' => $quote->id, 'amount' => 2500, 'status' => 'pending_first']);
        $this->assertSame(50000.0, (float) $quote->fresh()->price_amount);
    }

    public function test_sales_alert_command_detects_due_and_stale_opportunities_idempotently(): void
    {
        SalesLead::create([
            'lead_no' => 'LEAD-STALE', 'company_name' => 'فرصة راكدة', 'stage' => 'proposal',
            'estimated_value' => 30000, 'next_action_on' => now()->subDay(),
            'last_contacted_at' => now()->subDays(20), 'owner_user_id' => $this->marketer->id,
        ]);
        $this->artisan('darak:sales-alerts')->assertSuccessful();
        $first = NotificationMessage::where('user_id', $this->marketer->id)->where('type', 'sales.alert')->count();
        $this->assertSame(2, $first);
        $this->artisan('darak:sales-alerts')->assertSuccessful();
        $this->assertSame($first, NotificationMessage::where('user_id', $this->marketer->id)->where('type', 'sales.alert')->count());
    }

    public function test_marketer_cannot_edit_another_marketers_lead_and_permission_is_required(): void
    {
        $other = $this->panelUser('pipeline-owner@test.local', ['sales'], $this->branch->id);
        $lead = SalesLead::create(['lead_no' => 'LEAD-LOCKED', 'company_name' => 'فرصة محمية', 'stage' => 'new', 'estimated_value' => 1000, 'owner_user_id' => $other->id]);
        $this->actingAs($this->marketer, 'web')->post(route('sales.leads.activity', $lead), [
            'type' => 'note', 'note' => 'محاولة تعديل', 'occurred_at' => now(), 'stage' => 'won',
        ])->assertForbidden();

        $restricted = $this->panelUser('no-sales@test.local', ['clients'], $this->branch->id);
        $this->actingAs($restricted, 'web')->get(route('sales.home'))->assertForbidden();
    }

    /** @param array<int, string> $permissions */
    private function panelUser(string $email, array $permissions, int $branchId): User
    {
        $user = User::create([
            'name' => str($email)->before('@')->headline()->toString(), 'email' => $email,
            'password' => Hash::make('secret'), 'role' => User::ROLE_ADMIN,
            'operating_company_id' => $this->branch->operating_company_id,
            'operating_branch_id' => $branchId, 'permissions' => $permissions, 'is_active' => true,
        ]);
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }
}
