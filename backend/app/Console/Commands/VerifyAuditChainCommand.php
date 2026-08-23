<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

class VerifyAuditChainCommand extends Command
{
    protected $signature = 'darak:audit-verify';

    protected $description = 'Verify the append-only audit hash chain';

    public function handle(): int
    {
        $previous = null;
        $verified = 0;
        foreach (AuditLog::orderBy('id')->cursor() as $row) {
            if ($row->entry_hash === null) {
                continue; // legacy entry created before hash chaining was enabled
            }
            $payload = [
                'previous_hash' => $row->previous_hash, 'user_id' => $row->user_id,
                'action' => $row->action, 'auditable_type' => $row->auditable_type,
                'auditable_id' => $row->auditable_id, 'before' => $row->before,
                'after' => $row->after, 'ip' => $row->ip, 'user_agent' => $row->user_agent,
                'created_at' => $row->created_at,
            ];
            if ($row->previous_hash !== $previous || ! hash_equals($row->entry_hash, AuditLogger::digest($payload))) {
                $this->error('Audit chain failed at entry #'.$row->id);

                return self::FAILURE;
            }
            $previous = $row->entry_hash;
            $verified++;
        }
        $this->info("Audit chain valid ({$verified} hashed entries). ");

        return self::SUCCESS;
    }
}
