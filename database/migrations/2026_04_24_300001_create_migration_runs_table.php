<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity_type', 32);
            $table->string('source', 16);
            $table->integer('source_bytes')->default(0);
            $table->text('source_payload')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->jsonb('proposed_mapping')->nullable();
            $table->jsonb('confirmed_mapping')->nullable();
            $table->string('status', 32);
            $table->jsonb('stats')->default('{}');
            $table->jsonb('errors')->default('[]');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['team_id', 'created_at'], 'migration_runs_team_created_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                "CREATE INDEX migration_runs_active_idx ON migration_runs (status) WHERE status IN ('pending','analysing','running')",
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS migration_runs_active_idx');
        }
        Schema::dropIfExists('migration_runs');
    }
};
