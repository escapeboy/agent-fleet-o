<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enhance approval_requests for rich human tasks
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->uuid('workflow_node_id')->nullable()->after('outbound_proposal_id');
            $table->jsonb('form_schema')->nullable()->after('context');
            $table->jsonb('form_response')->nullable()->after('form_schema');
            $table->string('assignment_policy', 50)->default('specific_user')->after('form_response');
            $table->uuid('assigned_to')->nullable()->after('assignment_policy');
            $table->timestamp('sla_deadline')->nullable()->after('expires_at');
            $table->jsonb('escalation_chain')->nullable()->after('sla_deadline');
            $table->unsignedSmallInteger('escalation_level')->default(0)->after('escalation_chain');

            $table->index('sla_deadline', 'idx_approval_requests_sla');
            $table->index('assigned_to', 'idx_approval_requests_assigned');
            $table->foreign('workflow_node_id')->references('id')->on('workflow_nodes')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
        });

        // Add case_value to workflow_edges for SWITCH node routing
        Schema::table('workflow_edges', function (Blueprint $table) {
            $table->string('case_value', 255)->nullable()->after('condition');
        });

        // Add expression to workflow_nodes for SWITCH evaluation
        Schema::table('workflow_nodes', function (Blueprint $table) {
            $table->string('expression', 500)->nullable()->after('config');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_nodes', function (Blueprint $table) {
            $table->dropColumn('expression');
        });

        Schema::table('workflow_edges', function (Blueprint $table) {
            $table->dropColumn('case_value');
        });

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropForeign(['workflow_node_id']);
            $table->dropForeign(['assigned_to']);
            $table->dropIndex('idx_approval_requests_sla');
            $table->dropIndex('idx_approval_requests_assigned');
            $table->dropColumn([
                'workflow_node_id',
                'form_schema',
                'form_response',
                'assignment_policy',
                'assigned_to',
                'sla_deadline',
                'escalation_chain',
                'escalation_level',
            ]);
        });
    }
};
