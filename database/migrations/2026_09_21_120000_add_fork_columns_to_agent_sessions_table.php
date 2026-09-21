<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            // nullOnDelete, not cascade: CleanupAgentSessionEvents deletes orphaned
            // terminal parents at 90 days, and a fork must outlive its parent.
            $table->uuid('parent_session_id')->nullable()->after('crew_execution_id');
            $table->bigInteger('forked_at_seq')->nullable()->after('parent_session_id');

            $table->foreign('parent_session_id')
                ->references('id')->on('agent_sessions')
                ->nullOnDelete();

            $table->index(['team_id', 'parent_session_id']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->dropForeign(['parent_session_id']);
            $table->dropIndex(['team_id', 'parent_session_id']);
            $table->dropColumn(['parent_session_id', 'forked_at_seq']);
        });
    }
};
