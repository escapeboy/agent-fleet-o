<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('actor_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('target_type'); // tool_call | outbound | integration_action | git_push | ...
            $table->string('target_id')->nullable();
            $table->string('summary');
            $table->jsonb('payload')->default('{}');
            $table->jsonb('lineage')->default('[]');
            $table->string('risk_level')->default('high'); // low | medium | high
            $table->string('status')->default('pending'); // pending | approved | rejected | expired
            $table->timestamp('expires_at')->nullable();
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });

        // Partial index for the expiry sweeper. Laravel's rawIndex wraps the
        // expression in parens which breaks the WHERE clause, so we issue raw
        // SQL via DB::statement.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX action_proposals_team_pending_expiry_idx ON action_proposals (team_id, expires_at) WHERE status = 'pending'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('action_proposals');
    }
};
