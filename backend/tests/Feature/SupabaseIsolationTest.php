<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\DarakTestCase;

class SupabaseIsolationTest extends DarakTestCase
{
    public function test_every_public_postgresql_table_has_rls_enabled(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific defense-in-depth assertion.');
        }

        $unprotected = DB::select(<<<'SQL'
SELECT c.relname
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public'
  AND c.relkind IN ('r', 'p')
  AND c.relrowsecurity = false
ORDER BY c.relname
SQL);

        $this->assertSame([], array_column($unprotected, 'relname'));
    }

    public function test_supabase_api_roles_have_no_public_table_privileges_when_present(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific defense-in-depth assertion.');
        }

        $exposed = DB::select(<<<'SQL'
SELECT grantee, table_name, privilege_type
FROM information_schema.role_table_grants
WHERE table_schema = 'public'
  AND grantee IN ('anon', 'authenticated')
SQL);

        $this->assertSame([], $exposed);
    }
}
