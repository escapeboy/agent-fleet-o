<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->uuid('playbook_step_id')->nullable()->index();
            $table->uuid('workflow_node_id')->nullable();
            $table->string('event_type', 30);
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->jsonb('graph_state');
            $table->jsonb('step_input')->nullable();
            $table->jsonb('step_output')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->unsignedInteger('duration_from_start_ms')->default(0);
            $table->timestamp('created_at');

            $table->index(['experiment_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_snapshots');
    }
};
