<?php

namespace Tests\Feature;

use App\Models\ChecklistInstance;
use App\Models\Contact;
use App\Models\MediaFile;
use App\Models\NotificationMessage;
use App\Models\Visit;
use App\Services\NotificationService;
use App\Services\VisitStateMachine;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * The outbox. What matters is that a message can never exist for something that
 * did not happen, and that nothing fails silently.
 */
class NotificationTest extends DarakTestCase
{
    public function test_closing_a_visit_queues_the_report_message(): void
    {
        Contact::create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'name' => 'مدير الفرع',
            'phone' => '0551234567',
            'can_approve' => true,
        ]);

        $this->completeVisit();

        $message = NotificationMessage::where('type', NotificationMessage::TYPE_REPORT_READY)->first();

        $this->assertNotNull($message);
        $this->assertSame($this->visit->id, $message->visit_id);
        $this->assertSame(NotificationMessage::CHANNEL_WHATSAPP_MANUAL, $message->channel);
        $this->assertStringContainsString($this->client->name, $message->body);
    }

    public function test_a_blocked_close_produces_no_message(): void
    {
        $machine = app(VisitStateMachine::class);

        foreach ([Visit::STATE_EN_ROUTE, Visit::STATE_STARTED, Visit::STATE_AWAITING_CLOSE] as $state) {
            $machine->transition($this->visit->refresh(), $state);
        }

        try {
            $machine->transition($this->visit->refresh(), Visit::STATE_COMPLETED);
            $this->fail('Close should have been blocked.');
        } catch (\App\Exceptions\VisitCloseBlocked) {
            // expected
        }

        $this->assertSame(0, NotificationMessage::count(), 'no message for a visit that never closed');
    }

    public function test_the_same_event_never_queues_twice(): void
    {
        $notifications = app(NotificationService::class);

        $notifications->slaAtRisk($this->visit, 30);
        $notifications->slaAtRisk($this->visit, 20);
        $notifications->slaAtRisk($this->visit, 10);

        $this->assertSame(1, NotificationMessage::where('type', NotificationMessage::TYPE_SLA_AT_RISK)->count());
    }

    public function test_whatsapp_link_normalises_a_saudi_local_number(): void
    {
        $message = app(NotificationService::class)
            ->reportReady($this->visit, '0551234567');

        $this->assertStringStartsWith('https://wa.me/966551234567?text=', $message->whatsappLink());
    }

    public function test_a_message_without_a_phone_has_no_link(): void
    {
        $message = app(NotificationService::class)->reportReady($this->visit, null);

        $this->assertNull($message->whatsappLink());
    }

    public function test_manual_whatsapp_is_never_marked_sent_by_the_system(): void
    {
        $notifications = app(NotificationService::class);
        $message = $notifications->reportReady($this->visit, '0551234567');

        $this->assertSame('awaiting_human', $notifications->deliver($message));
        $this->assertSame('queued', $message->refresh()->status);

        // Only an explicit human action closes it.
        $notifications->markSentByHuman($message);
        $this->assertSame('sent', $message->refresh()->status);
    }

    public function test_repeated_failures_end_in_a_dead_letter_not_silence(): void
    {
        $notifications = app(NotificationService::class);
        $message = $notifications->slaAtRisk($this->visit, 15);

        for ($i = 0; $i < 5; $i++) {
            $notifications->recordFailure($message->refresh(), 'provider unreachable');
        }

        $message->refresh();

        $this->assertSame('dead', $message->status);
        $this->assertSame(5, $message->attempts);
        $this->assertStringContainsString('provider unreachable', $message->last_error);
    }

    public function test_failed_messages_back_off_before_retrying(): void
    {
        $notifications = app(NotificationService::class);
        $message = $notifications->slaAtRisk($this->visit, 15);

        $notifications->recordFailure($message, 'temporary');

        $message->refresh();

        $this->assertSame('queued', $message->status);
        $this->assertTrue($message->available_at->isFuture(), 'a retry must wait');
        $this->assertCount(0, $notifications->due()->where('id', $message->id));
    }

    public function test_the_panel_lists_queued_and_dead_messages(): void
    {
        $notifications = app(NotificationService::class);
        $notifications->reportReady($this->visit, '0551234567');

        $dead = $notifications->slaAtRisk($this->visit, 5);
        for ($i = 0; $i < 5; $i++) {
            $notifications->recordFailure($dead->refresh(), 'boom');
        }

        $this->actingAs($this->owner, 'web')
            ->get(route('panel.notifications'))
            ->assertOk()
            ->assertSee('فتح واتساب')
            ->assertSee('فشلت نهائياً');
    }

    private function completeVisit(): void
    {
        $machine = app(VisitStateMachine::class);

        $instance = ChecklistInstance::create([
            'visit_id' => $this->visit->id,
            'asset_id' => $this->asset->id,
            'status' => 'ok',
            'no_parts_used' => true,
            'completed_at' => now(),
        ]);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'checklist_instance_id' => $instance->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'upload_state' => 'complete',
        ]);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'signature',
            'upload_state' => 'complete',
        ]);

        foreach ([Visit::STATE_EN_ROUTE, Visit::STATE_STARTED, Visit::STATE_AWAITING_CLOSE, Visit::STATE_COMPLETED] as $state) {
            $machine->transition($this->visit->refresh(), $state);
        }
    }
}
