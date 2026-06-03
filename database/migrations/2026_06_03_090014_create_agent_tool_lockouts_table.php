<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reviewer-lockout (Squad borrow): a durable, inspectable record that encodes a
 * review decision into runtime state. When active, ToolCallGovernor blocks a
 * mutating tool call whose target matches `resource` — the original agent cannot
 * simply re-edit a rejected artifact; a human or different agent must take over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tool_lockouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            // null agent_id = team-wide lockout (applies to every agent in the team).
            $table->foreignUuid('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('resource');
            $table->string('match_mode')->default('equals');
            $table->text('reason')->nullable();
            $table->foreignUuid('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        // Hot path: active lockouts for a team (+ optional agent). Multi-column
        // partial index built via raw SQL (Blueprint::rawIndex double-wraps the
        // parens and breaks for composite expressions).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX idx_agent_tool_lockouts_active ON agent_tool_lockouts (team_id, agent_id) WHERE released_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_lockouts');
    }
};
