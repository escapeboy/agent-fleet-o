<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->jsonb('heartbeat_definition')->nullable()->after('last_health_check');
        });

        // Partial index for efficient heartbeat polling (PostgreSQL only)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE INDEX IF NOT EXISTS agents_heartbeat_next_run_idx
                 ON agents ((heartbeat_definition->>'next_run_at'))
                 WHERE heartbeat_definition->>'enabled' = 'true'
                   AND deleted_at IS NULL",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS agents_heartbeat_next_run_idx');
        }

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('heartbeat_definition');
        });
    }
};
