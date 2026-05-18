<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Tenure-inspired structured belief fields to the memories table:
 * typed taxonomy, preference subtype, action-oriented rationale, belief
 * lifecycle status, and a retrieval domain scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->string('belief_type', 20)->nullable()->after('category');
            $table->string('preference_subtype', 20)->nullable()->after('belief_type');
            $table->text('why_it_matters')->nullable()->after('preference_subtype');
            $table->string('belief_status', 20)->default('active')->after('why_it_matters');
            $table->string('domain', 64)->nullable()->after('belief_status');

            $table->index(['team_id', 'belief_status'], 'memories_team_belief_status_idx');
            $table->index(['agent_id', 'domain'], 'memories_agent_domain_idx');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropIndex('memories_team_belief_status_idx');
            $table->dropIndex('memories_agent_domain_idx');

            $table->dropColumn([
                'belief_type',
                'preference_subtype',
                'why_it_matters',
                'belief_status',
                'domain',
            ]);
        });
    }
};
