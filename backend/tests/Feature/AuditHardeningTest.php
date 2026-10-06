<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Tests\DarakTestCase;

class AuditHardeningTest extends DarakTestCase
{
    private const KEY = 'test-audit-hmac-key-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config(['darak.audit.hmac_key' => null, 'darak.audit.keep_full_ip' => false]);
    }

    public function test_rows_use_hmac_when_the_key_is_configured_and_mixed_chains_verify(): void
    {
        $logger = app(AuditLogger::class);
        $legacy = $logger->record('test.legacy', $this->client, null, ['n' => 1], $this->owner->id);

        config(['darak.audit.hmac_key' => self::KEY]);
        $sealed = $logger->record('test.sealed', $this->client, null, ['n' => 2], $this->owner->id);

        $this->assertSame(AuditLogger::HASH_SHA256, (int) $legacy->fresh()->hash_version);
        $this->assertSame(AuditLogger::HASH_HMAC_SHA256, (int) $sealed->fresh()->hash_version);
        $this->assertSame($legacy->entry_hash, $sealed->previous_hash);
        $this->assertNotSame(AuditLogger::digest($sealed->only([
            'previous_hash', 'user_id', 'action', 'auditable_type', 'auditable_id', 'before', 'after', 'ip', 'user_agent', 'created_at',
        ])), $sealed->entry_hash, 'v2 rows must not be a plain SHA-256 digest');

        $this->artisan('darak:audit-verify')
            ->expectsOutputToContain('Audit chain valid (2 hashed entries)')
            ->expectsOutputToContain('HMAC-SHA256 (v2): 1')
            ->assertSuccessful();
    }

    public function test_verify_reports_a_wrong_key_and_a_missing_key(): void
    {
        config(['darak.audit.hmac_key' => self::KEY]);
        $row = app(AuditLogger::class)->record('test.sealed', $this->client, null, ['n' => 1], $this->owner->id);

        config(['darak.audit.hmac_key' => 'a-different-key-entirely-0123456789']);
        $this->artisan('darak:audit-verify')
            ->expectsOutputToContain('Audit chain failed at entry #'.$row->id.': entry hash does not match')
            ->assertFailed();

        config(['darak.audit.hmac_key' => null]);
        $this->artisan('darak:audit-verify')
            ->expectsOutputToContain('DARAK_AUDIT_HMAC_KEY is not configured')
            ->assertFailed();
    }

    public function test_a_forged_plain_sha256_row_after_hmac_rows_is_a_downgrade(): void
    {
        config(['darak.audit.hmac_key' => self::KEY]);
        $sealed = app(AuditLogger::class)->record('test.sealed', $this->client, null, ['n' => 1], $this->owner->id);

        // An attacker without the key can only append a plain SHA-256 row.
        $payload = [
            'previous_hash' => $sealed->entry_hash, 'user_id' => null, 'action' => 'test.forged',
            'auditable_type' => null, 'auditable_id' => null, 'before' => null, 'after' => ['n' => 2],
            'ip' => null, 'user_agent' => null, 'created_at' => now()->startOfSecond(),
        ];
        $forgedId = DB::table('audit_logs')->insertGetId([
            ...$payload,
            'after' => json_encode(['n' => 2]),
            'hash_version' => AuditLogger::HASH_SHA256,
            'entry_hash' => AuditLogger::digest($payload),
            'updated_at' => $payload['created_at'],
        ]);

        $this->artisan('darak:audit-verify')
            ->expectsOutputToContain('Audit chain failed at entry #'.$forgedId.': downgrade')
            ->assertFailed();
    }

    public function test_personal_data_is_redacted_with_a_stable_fingerprint(): void
    {
        config(['darak.audit.hmac_key' => self::KEY]);
        $logger = app(AuditLogger::class);

        $first = $logger->record('sales.lead_created', $this->client, null, [
            'company_name' => 'Cafe One', 'contact_name' => 'Sara', 'phone' => '0551234567',
            'email' => 'Sara@Example.com', 'notes' => 'Call after 5pm on her personal line',
            'nested' => ['emergency_contact_phone' => '0559999999', 'iqama_number' => '2345678901'],
        ], $this->owner->id);
        $second = $logger->record('fleet.document_added', $this->client, null, [
            'phone' => '0551234567', 'email' => 'sara@example.com ', 'notes' => 'Oil change booked',
        ], $this->owner->id);

        $after = $first->fresh()->after;
        $this->assertSame('Cafe One', $after['company_name']);
        foreach (['contact_name', 'phone', 'email', 'notes'] as $key) {
            $this->assertMatchesRegularExpression('/^\[redacted\]:[0-9a-f]{8}$/', $after[$key], $key);
        }
        $this->assertMatchesRegularExpression('/^\[redacted\]:[0-9a-f]{8}$/', $after['nested']['emergency_contact_phone']);
        $this->assertMatchesRegularExpression('/^\[redacted\]:[0-9a-f]{8}$/', $after['nested']['iqama_number']);
        $this->assertStringNotContainsString('0551234567', json_encode($first->fresh()->getAttributes()));

        $other = $second->fresh()->after;
        // Same person, same tag: investigators can still correlate the entries.
        $this->assertSame($after['phone'], $other['phone']);
        $this->assertSame($after['email'], $other['email']);
        // Free text outside the listed contexts is operational, not personal.
        $this->assertSame('Oil change booked', $other['notes']);

        $this->artisan('darak:audit-verify')->assertSuccessful();
    }

    public function test_dispute_text_is_redacted(): void
    {
        $row = app(AuditLogger::class)->record('report.disputed', $this->visit, null, [
            'category' => 'work', 'description' => 'The technician was rude to my mother', 'status' => 'new',
        ]);

        $this->assertSame('work', $row->fresh()->after['category']);
        $this->assertStringStartsWith(AuditLogger::REDACTED, $row->fresh()->after['description']);
    }

    public function test_ip_is_truncated_and_user_agent_hashed_unless_full_values_are_kept(): void
    {
        $this->useRequest('203.0.113.77', 'Mozilla/5.0 (Linux; Android 14) Darak/1.0');
        $row = app(AuditLogger::class)->record('test.network', $this->client);

        $this->assertSame('203.0.113.0/24', $row->ip);
        $this->assertMatchesRegularExpression('/^hmac:[0-9a-f]{32}$/', (string) $row->user_agent);

        config(['darak.audit.keep_full_ip' => true]);
        $full = app(AuditLogger::class)->record('test.network', $this->client);

        $this->assertSame('203.0.113.77', $full->ip);
        $this->assertSame('Mozilla/5.0 (Linux; Android 14) Darak/1.0', $full->user_agent);
        $this->artisan('darak:audit-verify')->assertSuccessful();
    }

    public function test_ip_truncation_covers_ipv4_ipv6_and_mapped_addresses(): void
    {
        $this->assertSame('198.51.100.0/24', AuditLogger::truncateIp('198.51.100.200'));
        $this->assertSame('2001:db8:abcd::/48', AuditLogger::truncateIp('2001:db8:abcd:12:34:56:78:9a'));
        $this->assertSame('192.0.2.0/24', AuditLogger::truncateIp('::ffff:192.0.2.9'));
        $this->assertNull(AuditLogger::truncateIp('not-an-ip'));
    }

    public function test_append_uses_an_advisory_lock_instead_of_a_table_lock(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL locking behaviour.');
        }

        DB::transaction(function (): void {
            app(AuditLogger::class)->record('test.lock', $this->client);

            $tableLocks = DB::selectOne(
                "select count(*) as n from pg_locks l join pg_class c on c.oid = l.relation
                 where l.pid = pg_backend_pid() and c.relname = 'audit_logs' and l.mode in ('ShareRowExclusiveLock', 'ExclusiveLock', 'AccessExclusiveLock')"
            );
            $advisory = DB::selectOne(
                "select count(*) as n from pg_locks where pid = pg_backend_pid() and locktype = 'advisory'"
            );

            $this->assertSame(0, (int) $tableLocks->n);
            $this->assertGreaterThan(0, (int) $advisory->n);
        });
    }

    public function test_truncate_is_refused_by_the_database(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL trigger.');
        }

        app(AuditLogger::class)->record('test.keep', $this->client);
        $before = AuditLog::count();

        try {
            DB::transaction(fn () => DB::statement('TRUNCATE audit_logs'));
            $this->fail('TRUNCATE must be refused.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertSame($before, AuditLog::count());
    }

    private function useRequest(string $ip, string $agent): void
    {
        $this->app->instance('request', Request::create('/', 'GET', server: [
            'REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $agent,
        ]));
        Facade::clearResolvedInstance('request');
    }
}
