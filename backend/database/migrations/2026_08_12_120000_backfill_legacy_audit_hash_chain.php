<?php

use App\Services\AuditLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_immutable ON audit_logs;');
        }

        DB::transaction(function (): void {
            $previous = null;
            foreach (DB::table('audit_logs')->orderBy('id')->get() as $row) {
                $before = $row->before === null ? null : json_decode($row->before, true);
                $after = $row->after === null ? null : json_decode($row->after, true);
                $payload = [
                    'previous_hash' => $previous, 'user_id' => $row->user_id,
                    'action' => $row->action, 'auditable_type' => $row->auditable_type,
                    'auditable_id' => $row->auditable_id, 'before' => $before, 'after' => $after,
                    'ip' => $row->ip, 'user_agent' => $row->user_agent, 'created_at' => $row->created_at,
                ];
                $hash = AuditLogger::digest($payload);
                DB::table('audit_logs')->where('id', $row->id)->update(['previous_hash' => $previous, 'entry_hash' => $hash]);
                $previous = $hash;
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER audit_logs_immutable BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION darak_prevent_audit_mutation();');
        }
    }

    public function down(): void
    {
        // Hashes are evidentiary data. A rollback must never erase or rewrite them.
    }
};
