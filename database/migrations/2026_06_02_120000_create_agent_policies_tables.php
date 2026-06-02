<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Policy-Governed Autonomy (idea B).
 *
 * `agent_policies` is the current-pointer row, one active policy per scope
 * (team-default when agent_id is null, agent-specific otherwise).
 * `agent_policy_versions` are immutable snapshots — the versioned policy
 * "event" that can be rolled back to without redeploying. The pinned
 * version id on action_proposals (idea C) records which policy was in force
 * at decision time so the explanation is reproducible after the policy
 * changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            // null agent_id = team-default policy; non-null = agent-specific.
            $table->foreignUuid('agent_id')->nullable()->constrained('agents')->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('active'); // active | archived
            $table->boolean('enabled')->default(false);   // per-policy opt-in, AND-ed with global flag
            $table->uuid('current_version_id')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            // One active policy per scope. Postgres treats NULL agent_id rows as
            // distinct, so a partial unique index can't enforce a single
            // team-default; the resolver picks the most-recent active and the
            // CreateAgentPolicyAction archives the prior active for a scope.
            $table->index(['team_id', 'agent_id', 'status']);
        });

        Schema::create('agent_policy_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_policy_id')->constrained('agent_policies')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('rules')->default('{}');
            $table->text('notes')->nullable();
            $table->uuid('rolled_back_from_version_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['agent_policy_id', 'version']);
            $table->index('team_id');
        });

        // current_version_id FK added after the versions table exists.
        Schema::table('agent_policies', function (Blueprint $table): void {
            $table->foreign('current_version_id')
                ->references('id')->on('agent_policy_versions')
                ->nullOnDelete();
        });

        // Idea C: pin the policy version in force when the proposal was scored.
        Schema::table('action_proposals', function (Blueprint $table): void {
            $table->uuid('agent_policy_version_id')->nullable()->after('rubric_breakdown');
            $table->foreign('agent_policy_version_id')
                ->references('id')->on('agent_policy_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('action_proposals', function (Blueprint $table): void {
            $table->dropForeign(['agent_policy_version_id']);
            $table->dropColumn('agent_policy_version_id');
        });

        Schema::table('agent_policies', function (Blueprint $table): void {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('agent_policy_versions');
        Schema::dropIfExists('agent_policies');
    }
};
