<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Laravel uses the table-owner connection, so non-FORCED RLS does not
        // change application queries. Data API roles remain denied even if a
        // future grant accidentally reintroduces table privileges.
        DB::unprepared(<<<'SQL'
DO $$
DECLARE table_identifier text;
BEGIN
    FOR table_identifier IN
        SELECT quote_ident(n.nspname) || '.' || quote_ident(c.relname)
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
    LOOP
        EXECUTE format('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', table_identifier);
    END LOOP;
END $$;
SQL);
    }

    public function down(): void
    {
        // Deliberately irreversible. A rollback must not disable a database
        // security boundary without an explicit, reviewed migration.
    }
};
