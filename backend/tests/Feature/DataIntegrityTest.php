<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MediaFile;
use App\Models\VisitEvent;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

class DataIntegrityTest extends DarakTestCase
{
    public function test_a_client_with_history_cannot_be_hard_deleted(): void
    {
        VisitEvent::create([
            'visit_id' => $this->visit->id,
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'note.added',
            'server_received_at' => now(),
        ]);

        MediaFile::create([
            'visit_id' => $this->visit->id,
            'client_media_id' => (string) Str::uuid(),
            'kind' => 'photo_after',
            'upload_state' => 'complete',
            'original_hash' => str_repeat('a', 64),
        ]);

        $this->expectExceptionMessage('لا يمكن حذف عميل');

        $this->client->forceDelete();
    }

    public function test_the_evidence_survives_a_refused_delete(): void
    {
        VisitEvent::create([
            'visit_id' => $this->visit->id,
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'note.added',
            'server_received_at' => now(),
        ]);

        try {
            $this->client->forceDelete();
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, VisitEvent::count());
        $this->assertNotNull(Client::find($this->client->id));
    }

    public function test_archiving_a_client_is_still_allowed_and_keeps_its_sites(): void
    {
        $this->client->delete();

        $this->assertSoftDeleted('clients', ['id' => $this->client->id]);
        $this->assertDatabaseHas('sites', ['id' => $this->site->id]);
        $this->assertDatabaseHas('visits', ['id' => $this->visit->id]);
    }
}
