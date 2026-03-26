<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The depends_on column is already JSONB and started_at already exists.
        // This migration adds a GIN index on depends_on to support efficient
        // jsonb_path_ops containment queries (whereJsonContains).
        // GIN indexes are PostgreSQL-only — skip on SQLite (test environment).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_crew_task_depends_on ON crew_task_executions USING GIN (depends_on)',
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_crew_task_depends_on');
        }
    }
};
