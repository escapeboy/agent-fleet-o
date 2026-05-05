<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->uuid('agent_id')->nullable()->index();
            $table->uuid('experiment_id')->nullable()->index();
            $table->uuid('crew_execution_id')->nullable()->index();
            $table->uuid('user_id')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->jsonb('workspace_contract_snapshot')->nullable();
            $table->string('last_known_sandbox_id', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_sessions');
    }
};
