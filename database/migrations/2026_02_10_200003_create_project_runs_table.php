<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->integer('run_number');
            $table->foreignUuid('experiment_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('crew_execution_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('trigger', 20)->default('schedule');
            $table->jsonb('input_data')->nullable();
            $table->text('output_summary')->nullable();
            $table->integer('spend_credits')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'run_number']);
            $table->index('experiment_id');

            // FK for crew_execution_id (nullable, may not exist yet)
            $table->foreign('crew_execution_id')->references('id')->on('crew_executions')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX project_runs_active_idx ON project_runs (project_id) WHERE status IN ('pending', 'running')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_runs');
    }
};
