<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL-only: sequences have separate ACLs from tables.
        // Skipped on SQLite (test environment).
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // The agent_fleet_rls role is used for RLS enforcement (SET ROLE).
        // PostgreSQL sequences have separate ACLs from their tables, so writes
        // to tables with auto-increment IDs fail unless the RLS role has USAGE
        // and SELECT on those sequences.
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO agent_fleet_rls');

        // Ensure future sequences (created by subsequent migrations) also get
        // the necessary privileges automatically.
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO agent_fleet_rls');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('REVOKE USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public FROM agent_fleet_rls');
    }
};
