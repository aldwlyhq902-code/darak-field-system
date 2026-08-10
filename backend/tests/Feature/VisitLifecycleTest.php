<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChecklistInstance;
use App\Models\MediaFile;
use App\Models\Visit;
use App\Services\VisitStateMachine;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * Acceptance criteria 2 and 6, plus the 409 transition rule and the audit trail.
 */
class VisitLifecycleTest extends DarakTestCase
{
    public function test_invalid_transition_returns_409_with_a_stable_code(): void
    {
        $response = $this->actingAs($this->technician)
            ->postJson("/api/v1/visits/{$this->visit->id}/transition", ['to' => Visit::STATE_COMPLETED]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'INVALID_VISIT_TRANSITION')
            ->assertJsonPath('from', Visit::STATE_SCHEDULED)
            ->assertJsonPath('to', Visit::STATE_COMPLETED);

        $this->assertSame(Visit::STATE_SCHEDULED, $this->visit->refresh()->state);
    }

    public function test_incomplete_close_is_rejected_with_the_full_missing_list(): void
    {
        $this->moveTo(Visit::STATE_AWAITING_CLOSE);

        $response = $this->actingAs($this->technician)
            ->postJson("/api/v1/visits/{$this->visit->id}/transition", ['to' => Visit::STATE_COMPLETED]);

        $response->assertStatus(422)->assertJsonPath('code', 'VISIT_CLOSE_BLOCKED');

        $codes = array_column($response->json('blockers'), 'code');

        $this->assertContains('NO_CHECKLIST', $codes);
        $this->assertContains('PARTS_NOT_DECLARED', $codes);
        $this->assertContains('SIGNATURE_MISSING', $codes);

        // Rejected means unchanged — not half-closed.
        $this->assertSame(Visit::STATE_AWAITING_CLOSE, $this->visit->refresh()->state);
    }

    public function test_complete_visit_closes_and_records_state_in_audit_log(): void
    {
        $this->moveTo(Visit::STATE_AWAITING_CLOSE);
        $this->satisfyCloseGate();

        $response = $this->actingAs($this->technician)
            ->postJson("/api/v1/visits/{$this->visit->id}/transition", ['to' => Visit::STATE_COMPLETED]);

        $response->assertOk();

        $visit = $this->visit->refresh();
        $this->assertSame(Visit::STATE_COMPLETED, $visit->state);
        $this->assertNotNull($visit->closed_at);
        $this->assertNull($visit->close_blockers);

        $audit = AuditLog::where('action', 'visit.state.completed')->first();
        $this->assertNotNull($audit, 'Closing a visit must be audited.');
        $this->assertSame(Visit::STATE_AWAITING_CLOSE, $audit->before['state']);
        $this->assertSame(Visit::STATE_COMPLETED, $audit->after['state']);
    }

    public function test_on_site_counter_accumulates_only_started_time(): void
    {
        $machine = app(VisitStateMachine::class);

        $this->travelTo(now()->startOfHour());
        $machine->transition($this->visit, Visit::STATE_EN_ROUTE);
        $machine->transition($this->visit->refresh(), Visit::STATE_STARTED);

        $this->travel(30)->minutes();
        $machine->transition($this->visit->refresh(), Visit::STATE_PAUSED);

        $this->travel(45)->minutes(); // paused — must not count
        $machine->transition($this->visit->refresh(), Visit::STATE_STARTED);

        $this->travel(10)->minutes();
        $machine->transition($this->visit->refresh(), Visit::STATE_AWAITING_CLOSE);

        $this->assertEqualsWithDelta(40 * 60, $this->visit->refresh()->on_site_seconds, 5);
    }

    private function moveTo(string $target): void
    {
        $machine = app(VisitStateMachine::class);
        $path = [Visit::STATE_EN_ROUTE, Visit::STATE_STARTED, Visit::STATE_AWAITING_CLOSE];

        foreach ($path as $state) {
            $machine->transition($this->visit->refresh(), $state, ['actor_user_id' => $this->technician->id]);

            if ($state === $target) {
                return;
            }
        }
    }

    private function satisfyCloseGate(): void
    {
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
            'original_path' => 'media/test/photo.jpg',
            'original_hash' => str_repeat('a', 64),
        ]);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'signature',
            'upload_state' => 'complete',
            'original_path' => 'media/test/sig.png',
            'original_hash' => str_repeat('b', 64),
        ]);
    }
}
