<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

class VerifyAuditChainCommand extends Command
{
    protected $signature = 'darak:audit-verify';

    protected $description = 'Verify the append-only audit hash chain (SHA-256 v1 and HMAC-SHA256 v2 entries)';

    public function handle(): int
    {
        $key = AuditLogger::hmacKey();
        $previous = null;
        $counts = [AuditLogger::HASH_SHA256 => 0, AuditLogger::HASH_HMAC_SHA256 => 0];
        $legacy = 0;
        $hmacStarted = false;

        foreach (AuditLog::orderBy('id')->cursor() as $row) {
            if ($row->entry_hash === null) {
                $legacy++;

                continue; // legacy entry created before hash chaining was enabled
            }
            $version = (int) ($row->hash_version ?? AuditLogger::HASH_SHA256);
            $payload = [
                'previous_hash' => $row->previous_hash, 'user_id' => $row->user_id,
                'action' => $row->action, 'auditable_type' => $row->auditable_type,
                'auditable_id' => $row->auditable_id, 'before' => $row->before,
                'after' => $row->after, 'ip' => $row->ip, 'user_agent' => $row->user_agent,
                'created_at' => $row->created_at,
            ];

            $failure = match (true) {
                $row->previous_hash !== $previous => 'previous_hash does not match the preceding entry',
                ! array_key_exists($version, $counts) => "unknown hash_version {$version}",
                $version === AuditLogger::HASH_HMAC_SHA256 && $key === null => 'HMAC entry but DARAK_AUDIT_HMAC_KEY is not configured',
                // Once HMAC sealing began, a plain SHA-256 row means either the key
                // went missing at write time or history was re-sealed without it.
                $version === AuditLogger::HASH_SHA256 && $hmacStarted => 'downgrade to plain SHA-256 after HMAC entries began',
                ! hash_equals($row->entry_hash, (string) AuditLogger::digestFor($payload, $version, $key)) => 'entry hash does not match its contents (wrong key or altered row)',
                default => null,
            };

            if ($failure !== null) {
                $this->error('Audit chain failed at entry #'.$row->id.': '.$failure);

                return self::FAILURE;
            }

            $hmacStarted = $hmacStarted || $version === AuditLogger::HASH_HMAC_SHA256;
            $counts[$version]++;
            $previous = $row->entry_hash;
        }

        $verified = array_sum($counts);
        $this->info("Audit chain valid ({$verified} hashed entries). ");
        $this->line(sprintf(
            '  SHA-256 (v1): %d · HMAC-SHA256 (v2): %d · unhashed legacy: %d',
            $counts[AuditLogger::HASH_SHA256], $counts[AuditLogger::HASH_HMAC_SHA256], $legacy,
        ));

        return self::SUCCESS;
    }
}
