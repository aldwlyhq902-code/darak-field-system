<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Custody;
use App\Models\Device;
use App\Models\IdempotencyKey;
use App\Models\Visit;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/** Contract C2: Idempotency-Key on mutating, non-sync API calls. */
class IdempotencyKeyTest extends DarakTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->technician->createToken('device:'.$this->device->device_uuid)->plainTextToken;
    }

    public function test_a_retried_request_replays_the_stored_response_without_a_second_side_effect(): void
    {
        $custody = $this->custody();
        $key = (string) Str::uuid();

        $first = $this->accept($custody, $key, 'Tech One')->assertOk();
        $second = $this->accept($custody, $key, 'Tech One')->assertOk();

        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->getContent(), $second->getContent());
        $first->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(1, AuditLog::where('action', 'custody.accepted')->count());
    }

    public function test_without_the_header_requests_are_processed_as_before(): void
    {
        $custody = $this->custody();

        $this->asToken($this->token)->postJson("/api/v1/custodies/{$custody->id}/accept", ['accepted_name' => 'A'])->assertOk();
        // Already accepted: the second, un-keyed call really runs and is refused.
        $this->asToken($this->token)->postJson("/api/v1/custodies/{$custody->id}/accept", ['accepted_name' => 'A'])->assertStatus(422);
        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_reusing_a_key_for_a_different_request_is_refused(): void
    {
        $custody = $this->custody();
        $other = $this->custody();
        $key = (string) Str::uuid();

        $this->accept($custody, $key, 'Tech One')->assertOk();

        $this->accept($custody, $key, 'Someone Else')
            ->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->accept($other, $key, 'Tech One')
            ->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame('issued', $other->refresh()->status);
    }

    public function test_a_concurrent_duplicate_does_not_execute_twice(): void
    {
        $custody = $this->custody();
        $key = (string) Str::uuid();
        $body = json_encode(['accepted_name' => 'Tech One']);

        // The first request has claimed the key and is still running.
        IdempotencyKey::create([
            'user_id' => $this->technician->id, 'idempotency_key' => $key, 'method' => 'POST',
            'path' => "/api/v1/custodies/{$custody->id}/accept",
            'request_hash' => hash('sha256', "POST\n/api/v1/custodies/{$custody->id}/accept\n".$body),
            'state' => IdempotencyKey::STATE_PROCESSING, 'expires_at' => now()->addDay(),
        ]);

        $this->asToken($this->token)
            ->call('POST', "/api/v1/custodies/{$custody->id}/accept", [], [], [], [
                'HTTP_IDEMPOTENCY_KEY' => $key, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ], $body)
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_REQUEST_IN_PROGRESS');

        $this->assertSame('issued', $custody->refresh()->status);
    }

    public function test_failures_are_not_remembered_so_a_corrected_retry_succeeds(): void
    {
        $custody = $this->custody();
        $key = (string) Str::uuid();

        $this->asToken($this->token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/custodies/{$custody->id}/accept", [])
            ->assertUnprocessable();
        $this->assertSame(0, IdempotencyKey::count());

        $this->accept($custody, $key, 'Tech One')->assertOk();
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $key = (string) Str::uuid();
        $mine = $this->custody();
        $theirs = $this->custody($this->otherTechnician->id);
        $otherDevice = Device::create(['user_id' => $this->otherTechnician->id, 'device_uuid' => (string) Str::uuid()]);
        $otherToken = $this->otherTechnician->createToken('device:'.$otherDevice->device_uuid)->plainTextToken;

        $this->accept($mine, $key, 'Tech One')->assertOk();

        $this->asToken($otherToken)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/custodies/{$theirs->id}/accept", ['accepted_name' => 'Tech Two'])
            ->assertOk()
            ->assertHeaderMissing('Idempotent-Replayed');
    }

    public function test_visit_transition_is_idempotent(): void
    {
        $key = (string) Str::uuid();
        $call = fn () => $this->asToken($this->token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/visits/{$this->visit->id}/transition", ['to' => Visit::STATE_EN_ROUTE]);

        $call()->assertOk();
        $call()->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, $this->visit->events()->where('event_type', 'like', 'state.%')->count());
    }

    public function test_expired_keys_are_pruned_and_reusable(): void
    {
        $custody = $this->custody();
        $key = (string) Str::uuid();
        $this->accept($custody, $key, 'Tech One')->assertOk();
        IdempotencyKey::query()->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, IdempotencyKey::pruneExpired());
        $this->assertSame(0, IdempotencyKey::count());

        $this->artisan('model:prune', ['--model' => [IdempotencyKey::class]])->assertSuccessful();
    }

    public function test_a_malformed_key_is_rejected(): void
    {
        $custody = $this->custody();

        $this->accept($custody, 'bad key!', 'Tech One')
            ->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_INVALID');
        $this->assertSame('issued', $custody->refresh()->status);
    }

    private function accept(Custody $custody, string $key, string $name)
    {
        return $this->asToken($this->token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/custodies/{$custody->id}/accept", ['accepted_name' => $name]);
    }

    private function custody(?int $userId = null): Custody
    {
        return Custody::create([
            'custody_no' => 'CUS-'.Str::upper(Str::random(8)),
            'user_id' => $userId ?? $this->technician->id,
            'item_type' => 'tool', 'item_name' => 'Gauge set',
            'condition_out' => 'good', 'issued_at' => now(), 'status' => 'issued',
            'issued_by' => $this->owner->id,
        ]);
    }
}
