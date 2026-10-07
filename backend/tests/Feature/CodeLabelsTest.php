<?php

namespace Tests\Feature;

use App\Models\ChecklistInstance;
use App\Models\ClientPortalUser;
use App\Models\NotificationMessage;
use App\Models\SalesActivity;
use App\Models\SalesLead;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitEvent;
use App\Services\VisitReportBuilder;
use App\Support\Labels;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * 0.3.0 regression: stored codes (completed, signature.captured, split_ac,
 * installment.due, basic…) were printed as-is in the Arabic interface. Every
 * screen now reads its labels from the one map, resources/lang/ar/labels.php.
 */
class CodeLabelsTest extends DarakTestCase
{
    public function test_panel_visit_page_labels_the_state_and_every_event(): void
    {
        $this->visit->forceFill(['state' => Visit::STATE_COMPLETED, 'closed_at' => now()])->save();

        foreach (['state.awaiting_close.to.completed', 'signature.captured', 'site.scanned', 'asset.scanned',
            'assignment.changed', 'checklist.upsert', 'media.register', 'visit.transition', 'geofence.ping'] as $type) {
            VisitEvent::create([
                'visit_id' => $this->visit->id,
                'client_event_id' => (string) Str::uuid(),
                'event_type' => $type,
                'server_received_at' => now(),
            ]);
        }

        $this->actingAs($this->owner, 'web')
            ->get(route('panel.visit', $this->visit))
            ->assertOk()
            ->assertSee('<td>مكتملة</td>', false)
            ->assertSeeText('تغيير الحالة: بانتظار الإقفال ← مكتملة')
            ->assertSeeText('توقيع مسؤول الموقع')
            ->assertSeeText('مسح ملصق الموقع')
            ->assertSeeText('مسح ملصق الأصل')
            ->assertSeeText('تغيير الفني المسند')
            ->assertSeeText('تسجيل حالة أصل')
            ->assertSeeText('تسجيل صورة أو ملف')
            ->assertDontSee('<td>completed</td>', false)
            ->assertDontSeeText('state.awaiting_close.to.completed')
            ->assertDontSeeText('signature.captured')
            ->assertDontSeeText('site.scanned')
            ->assertDontSeeText('assignment.changed')
            ->assertDontSeeText('checklist.upsert')
            ->assertDontSeeText('media.register');
    }

    public function test_team_page_names_technician_specialties(): void
    {
        $this->technician->forceFill([
            'specialties' => ['split_ac', 'package_ac', 'chiller', 'freezer', 'electrical', 'plumbing'],
        ])->save();

        $response = $this->actingAs($this->owner, 'web')->get(route('panel.team'))->assertOk()
            ->assertSeeText('تكييف سبليت · تكييف مركزي · تبريد · تجميد · كهرباء · سباكة');

        foreach (['split_ac', 'package_ac', 'chiller', 'freezer', 'electrical', 'plumbing', 'owner_supervisor'] as $code) {
            $response->assertDontSeeText($code);
        }
    }

    public function test_notifications_page_names_every_notification_type(): void
    {
        $types = [
            NotificationMessage::TYPE_INSTALLMENT_DUE => 'قسط مستحق',
            NotificationMessage::TYPE_SALES_ALERT => 'تنبيه مبيعات',
            NotificationMessage::TYPE_EMERGENCY_REPORTED => 'بلاغ طارئ',
            NotificationMessage::TYPE_CONTRACT_EXPIRING => 'عقد يقترب من الانتهاء',
            NotificationMessage::TYPE_FEEDBACK_ALERT => 'تنبيه تقييم العميل',
            NotificationMessage::TYPE_SECURITY_ALERT => 'تنبيه أمني',
            NotificationMessage::TYPE_MANUAL_INVOICE => 'فاتورة بانتظار الإصدار اليدوي',
            NotificationMessage::TYPE_VISIT_ASSIGNED => 'إسناد زيارة',
        ];

        foreach (array_keys($types) as $i => $type) {
            NotificationMessage::create([
                'type' => $type,
                'channel' => NotificationMessage::CHANNEL_IN_APP,
                'recipient_kind' => 'supervisor',
                'user_id' => $this->owner->id,
                'body' => "رسالة {$i}",
                'status' => ['queued', 'sent', 'dead'][$i % 3],
                'sent_at' => $i % 3 === 1 ? now() : null,
                'idempotency_key' => "labels-{$i}",
            ]);
        }

        $response = $this->actingAs($this->owner, 'web')->get(route('panel.notifications'))->assertOk();

        foreach ($types as $code => $label) {
            $response->assertSeeText($label)->assertDontSeeText($code);
        }
    }

    public function test_client_contract_page_names_the_package(): void
    {
        $portalUser = ClientPortalUser::create([
            'client_id' => $this->client->id,
            'name' => 'مدير العميل',
            'email' => 'labels-client@test.local',
            'password' => 'Strong-Client9!',
            'is_active' => true,
        ]);

        $this->actingAs($portalUser, 'client')
            ->get(route('client.contract', $this->contract))
            ->assertOk()
            ->assertSeeText($this->client->name.' · الباقة الأساسية')
            ->assertDontSeeText(' · basic');
    }

    public function test_visit_report_names_asset_types(): void
    {
        ChecklistInstance::create([
            'visit_id' => $this->visit->id,
            'asset_id' => $this->asset->id,
            'status' => 'ok',
            'completed_at' => now(),
        ]);
        $this->asset->forceFill(['type' => 'split_ac'])->save();

        $html = view('reports.visit', [
            'visit' => $this->visit->refresh()->load(['workOrder.client', 'site.client', 'checklistInstances.asset', 'technician']),
            'parts' => [],
            'photoRefs' => [],
            'signature' => null,
            'signatureRef' => null,
            'retiredAssets' => collect(),
            'templateVersion' => VisitReportBuilder::TEMPLATE_VERSION,
        ])->render();

        $this->assertStringContainsString('<td>مكيف سبليت</td>', $html);
        $this->assertStringNotContainsString('<td>split_ac</td>', $html);
    }

    public function test_sales_app_reads_sources_activities_and_stages_from_the_map(): void
    {
        $marketer = User::create([
            'name' => 'Labels Marketer', 'email' => 'labels-marketer@test.local',
            'password' => Hash::make('secret'), 'role' => User::ROLE_ADMIN,
            'operating_company_id' => $this->operatingCompany->id, 'operating_branch_id' => $this->operatingBranch->id,
            'permissions' => ['sales'], 'is_active' => true,
        ]);
        $marketer->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ])->save();
        $lead = SalesLead::create([
            'lead_no' => 'LEAD-LABELS', 'company_name' => 'مطعم التسميات', 'stage' => 'qualified',
            'source' => 'referral', 'estimated_value' => 12000, 'owner_user_id' => $marketer->id,
        ]);
        SalesActivity::create([
            'sales_lead_id' => $lead->id, 'user_id' => $marketer->id, 'type' => 'meeting',
            'note' => 'اجتماع تعريفي', 'occurred_at' => now(),
        ]);

        $this->actingAs($marketer, 'web')->get(route('sales.home'))
            ->assertOk()
            ->assertSeeText('توصية')
            ->assertSeeText('مطعم التسميات · اجتماع')
            ->assertSeeText(Labels::of('lead_stage', 'won'))
            ->assertSee('<option value="comprehensive"', false)
            ->assertSeeText('الباقة الشاملة');
    }

    public function test_labels_helper(): void
    {
        $this->assertSame('مكتملة', Labels::of('visit_state', 'completed'));
        $this->assertSame('تغيير الحالة: مجدولة ← في الطريق', Labels::visitEvent('state.scheduled.to.en_route'));
        $this->assertSame('توقيع مسؤول الموقع', Labels::visitEvent('signature.captured'));
        $this->assertSame('تكييف سبليت · كهرباء', Labels::list('specialty', ['split_ac', 'electrical']));
        $this->assertSame('—', Labels::list('specialty', []));
        $this->assertSame('—', Labels::of('package', null));
        // An unmapped code is shown, never silently dropped.
        $this->assertSame('brand_new_code', Labels::of('visit_state', 'brand_new_code'));
    }

    /**
     * The guard against new raw codes: no Blade view may print a bare coded
     * attribute ({{ $x->status }}, {{ $x->type }} …); it goes through Labels
     * or a local label map. Free-text fields are listed explicitly.
     */
    public function test_no_blade_view_prints_a_raw_code(): void
    {
        $freeText = [
            'panel/work-policies.blade.php' => ['$active->source', '$policy->source'],
        ];
        $pattern = '/\{\{\s*(\$[a-zA-Z_]+(?:\??->[a-zA-Z_]+)*\??->(?:state|status|type|role|kind|event_type|category|package_code|priority|source|channel|method|stage|action_type|subject_type|recipient_kind|upload_state))\s*\}\}|implode\([^)]*specialties/';
        $offenders = [];

        foreach (glob(resource_path('views/{,*/,*/*/}*.blade.php'), GLOB_BRACE) as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen(resource_path('views/'))));
            preg_match_all($pattern, (string) file_get_contents($file), $matches);

            foreach ($matches[0] as $index => $match) {
                if (! in_array($matches[1][$index] ?? '', $freeText[$relative] ?? [], true)) {
                    $offenders[] = "{$relative}: {$match}";
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
