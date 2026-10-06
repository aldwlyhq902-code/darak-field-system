<?php

namespace Tests\Feature;

use App\Models\NotificationMessage;
use App\Models\OperatingCompany;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Hash;
use Tests\DarakTestCase;

/**
 * Supervisor notifications (emergency reports, instalments due, expiring
 * contracts) have no recipient user. They used to carry no tenant either, so
 * the panel showed them to platform administrators only and the company owner
 * never saw them in the inbox.
 */
class NotificationVisibilityTest extends DarakTestCase
{
    public function test_company_owner_sees_supervisor_notifications_for_their_clients(): void
    {
        $this->contract->forceFill(['ends_on' => now()->addDays(20)->toDateString()])->save();

        $message = app(NotificationService::class)->contractExpiring($this->contract->refresh());

        $this->assertSame($this->operatingCompany->id, $message->context['operating_company_id'] ?? null);
        $this->assertFalse($this->owner->isPlatformAdmin());
        $this->actingAs($this->owner, 'web')->get(route('panel.notifications'))
            ->assertOk()->assertSee($this->contract->contract_no);
    }

    public function test_another_company_owner_does_not_see_them(): void
    {
        $this->contract->forceFill(['ends_on' => now()->addDays(20)->toDateString()])->save();
        app(NotificationService::class)->contractExpiring($this->contract->refresh());

        $otherOwner = $this->ownerOfAnotherCompany();

        $this->actingAs($otherOwner, 'web')->get(route('panel.notifications'))
            ->assertOk()->assertDontSee($this->contract->contract_no);
    }

    public function test_untagged_legacy_messages_are_visible_only_while_one_company_exists(): void
    {
        $legacy = NotificationMessage::create([
            'type' => NotificationMessage::TYPE_EMERGENCY_REPORTED, 'channel' => 'in_app',
            'recipient_kind' => 'supervisor', 'body' => 'بلاغ قديم بلا مستأجر',
            'status' => 'queued', 'attempts' => 0, 'idempotency_key' => 'legacy-untagged',
        ]);

        $this->actingAs($this->owner, 'web')->get(route('panel.notifications'))
            ->assertOk()->assertSee($legacy->body);

        $otherOwner = $this->ownerOfAnotherCompany();

        $this->actingAs($otherOwner, 'web')->get(route('panel.notifications'))
            ->assertOk()->assertDontSee($legacy->body);
        $this->actingAs($this->owner, 'web')->get(route('panel.notifications'))
            ->assertOk()->assertDontSee($legacy->body);
    }

    private function ownerOfAnotherCompany(): User
    {
        $company = OperatingCompany::create(['name' => 'Other Co', 'is_active' => true]);
        $owner = User::create([
            'name' => 'Other Owner', 'email' => 'other-owner@test.local', 'password' => Hash::make('secret'),
            'role' => User::ROLE_OWNER, 'is_active' => true, 'operating_company_id' => $company->id,
        ]);
        $owner->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $owner;
    }
}
