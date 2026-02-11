<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 20)->default('one_shot');
            $table->string('status', 20)->default('draft');
            $table->string('paused_from_status', 20)->nullable();
            $table->text('goal')->nullable();
            $table->foreignUuid('crew_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('agent_config')->default('{}');
            $table->jsonb('budget_config')->default('{}');
            $table->jsonb('notification_config')->default('{}');
            $table->jsonb('settings')->default('{}');
            $table->integer('total_runs')->default(0);
            $table->integer('successful_runs')->default(0);
            $table->integer('failed_runs')->default(0);
            $table->integer('total_spend_credits')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'type']);
        });

        // Partial index for active projects (scheduling queries)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX projects_active_continuous_idx ON projects (next_run_at) WHERE status = 'active' AND type = 'continuous'");
            DB::statement('CREATE INDEX projects_agent_config_gin_idx ON projects USING GIN (agent_config)');
            DB::statement('CREATE INDEX projects_budget_config_gin_idx ON projects USING GIN (budget_config)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
