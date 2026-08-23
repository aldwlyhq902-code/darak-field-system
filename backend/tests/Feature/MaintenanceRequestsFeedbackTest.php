<?php

namespace Tests\Feature;

use App\Models\ClientPortalUser;
use App\Models\ClientServiceRequest;
use App\Models\MaintenancePlan;
use App\Models\Visit;
use App\Services\DispatchSuggestionService;
use App\Services\MaintenancePlanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Tests\DarakTestCase;

class MaintenanceRequestsFeedbackTest extends DarakTestCase
{
    public function test_due_maintenance_plan_generates_one_visit_and_advances_due_date_idempotently(): void
    {
        $plan = MaintenancePlan::create([
            'client_id' => $this->client->id, 'contract_id' => $this->contract->id,
            'site_id' => $this->site->id, 'asset_id' => $this->asset->id,
            'title' => 'تنظيف وفحص التكييف', 'frequency_days' => 30,
            'duration_minutes' => 90, 'preferred_start' => '09:00',
            'next_due_on' => today(), 'created_by' => $this->owner->id, 'is_active' => true,
        ]);

        $service = app(MaintenancePlanService::class);
        $this->assertSame(1, $service->generateDuePlans());
        $this->assertSame(0, $service->generateDuePlans());
        $this->assertDatabaseHas('work_orders', ['wo_number' => 'WO-PM-'.$plan->id.'-'.today()->format('Ymd'), 'type' => 'preventive']);
        $this->assertCount(1, Visit::whereHas('workOrder', fn ($q) => $q->where('wo_number', 'WO-PM-'.$plan->id.'-'.today()->format('Ymd')))->get());
        $this->assertTrue($plan->refresh()->next_due_on->isAfter(today()));
    }

    public function test_inactive_or_inactive_contract_plan_does_not_generate(): void
    {
        MaintenancePlan::create([
            'client_id' => $this->client->id, 'contract_id' => $this->contract->id,
            'site_id' => $this->site->id, 'title' => 'Paused', 'frequency_days' => 30,
            'duration_minutes' => 60, 'next_due_on' => today(), 'is_active' => false,
        ]);
        $this->contract->forceFill(['status' => 'cancelled'])->save();
        MaintenancePlan::create([
            'client_id' => $this->client->id, 'contract_id' => $this->contract->id,
            'site_id' => $this->site->id, 'title' => 'Ended contract', 'frequency_days' => 30,
            'duration_minutes' => 60, 'next_due_on' => today(), 'is_active' => true,
        ]);

        $this->assertSame(0, app(MaintenancePlanService::class)->generateDuePlans());
    }

    public function test_client_can_request_a_slot_and_owner_can_convert_it_to_a_visit(): void
    {
        $portal = $this->portalUser();
        $date = today()->addDays(3)->toDateString();
        $this->actingAs($portal, 'client')->post(route('client.service-request.store'), [
            'site_id' => $this->site->id, 'asset_id' => $this->asset->id,
            'category' => 'reactive', 'description' => 'يوجد صوت مرتفع من وحدة التكييف عند التشغيل.',
            'preferred_date' => $date, 'preferred_time_slot' => 'morning',
        ])->assertRedirect(route('client.home'));

        $serviceRequest = ClientServiceRequest::firstOrFail();
        $this->actingAs($this->owner, 'web')->post(route('panel.service-request.convert', $serviceRequest), [
            'response_note' => 'تم تأكيد الفترة الصباحية.',
        ])->assertRedirect();

        $serviceRequest->refresh();
        $this->assertSame(ClientServiceRequest::STATUS_CONVERTED, $serviceRequest->status);
        $this->assertSame('07:00', $serviceRequest->visit->scheduled_start->format('H:i'));
        $this->assertNull($serviceRequest->visit->assigned_user_id);
    }

    public function test_client_cannot_request_an_asset_from_another_site(): void
    {
        $otherSite = $this->client->sites()->create(['name' => 'Other branch']);
        $otherAsset = $otherSite->assets()->create(['name' => 'Other asset', 'type' => 'freezer']);

        $this->actingAs($this->portalUser(), 'client')->post(route('client.service-request.store'), [
            'site_id' => $this->site->id, 'asset_id' => $otherAsset->id, 'category' => 'reactive',
            'description' => 'A sufficiently detailed service request description.',
            'preferred_date' => today()->addDays(2)->toDateString(), 'preferred_time_slot' => 'morning',
        ])->assertSessionHasErrors('asset_id');
    }

    public function test_dispatch_suggestion_respects_shift_specialty_and_overlap(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 08:00:00');
        $this->visit->forceFill([
            'assigned_user_id' => null,
            'scheduled_start' => CarbonImmutable::parse('2026-08-13 09:00'),
            'scheduled_end' => CarbonImmutable::parse('2026-08-13 11:00'),
        ])->save();

        $suggestions = app(DispatchSuggestionService::class)->suggest($this->visit);
        $this->assertSame($this->technician->id, $suggestions->first()['technician']->id);
        $this->assertFalse($suggestions->pluck('technician.id')->contains($this->otherTechnician->id));
    }

    public function test_low_feedback_opens_supervisor_alert_and_can_be_resolved(): void
    {
        $portal = $this->portalUser();
        $this->visit->forceFill(['state' => Visit::STATE_COMPLETED, 'closed_at' => now()])->save();

        $this->actingAs($portal, 'client')->post(route('client.visit.feedback', $this->visit), [
            'rating' => 2, 'resolution_confirmed' => 0, 'comment' => 'العطل ما زال موجودًا.',
        ])->assertRedirect();

        $feedback = $this->visit->feedback()->firstOrFail();
        $this->assertTrue($feedback->is_complaint);
        $this->assertDatabaseHas('notification_messages', ['type' => 'feedback.alert', 'visit_id' => $this->visit->id]);

        $this->actingAs($this->owner, 'web')->post(route('panel.feedback.review', $feedback), [
            'status' => 'resolved', 'supervisor_note' => 'تم التواصل وجدولة إعادة زيارة.',
        ])->assertRedirect();
        $this->assertSame('resolved', $feedback->refresh()->status);
    }

    public function test_feedback_is_limited_to_completed_owned_visit_and_one_submission(): void
    {
        $portal = $this->portalUser();
        $payload = ['rating' => 5, 'resolution_confirmed' => 1, 'comment' => 'ممتاز'];
        $this->actingAs($portal, 'client')->post(route('client.visit.feedback', $this->visit), $payload)->assertUnprocessable();
        $this->visit->forceFill(['state' => Visit::STATE_COMPLETED, 'closed_at' => now()])->save();
        $this->actingAs($portal, 'client')->post(route('client.visit.feedback', $this->visit), $payload)->assertRedirect();
        $this->actingAs($portal, 'client')->post(route('client.visit.feedback', $this->visit), $payload)->assertUnprocessable();
    }

    public function test_new_pages_render_for_the_correct_guards(): void
    {
        $this->actingAs($this->owner, 'web')->get(route('panel.maintenance'))->assertOk()->assertSee('خطط الصيانة الوقائية');
        $this->actingAs($this->portalUser(), 'client')->get(route('client.service-request'))->assertOk()->assertSee('طلب موعد صيانة');
    }

    private function portalUser(): ClientPortalUser
    {
        return ClientPortalUser::firstOrCreate(['email' => 'maintenance-client@test.local'], [
            'client_id' => $this->client->id, 'name' => 'Client Manager', 'phone' => '0501234567',
            'password' => Hash::make('Strong-Client9!'), 'is_active' => true,
        ]);
    }
}
