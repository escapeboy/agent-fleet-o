<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add GIN indexes for JSONB columns that are queried with containment operators.
 *
 * - artifacts.metadata: filtered by content type, category flags, and custom metadata keys
 * - projects.allowed_tool_ids: checked with @> for "does project allow this tool?"
 * - projects.allowed_credential_ids: checked with @> for "does project allow this credential?"
 *
 * GIN indexes support @>, ?, ?|, ?& operators on JSONB and array types.
 * They are write-heavy but dramatically speed up containment queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS artifacts_metadata_gin_idx ON artifacts USING GIN (metadata jsonb_path_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS projects_allowed_tool_ids_gin_idx ON projects USING GIN (allowed_tool_ids)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS projects_allowed_credential_ids_gin_idx ON projects USING GIN (allowed_credential_ids)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS artifacts_metadata_gin_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS projects_allowed_tool_ids_gin_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS projects_allowed_credential_ids_gin_idx');
    }
};
