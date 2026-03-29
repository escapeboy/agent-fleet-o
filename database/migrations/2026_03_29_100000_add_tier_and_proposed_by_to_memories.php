<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add memory tier system columns to the memories table.
 *
 * tier      — curation level (working/proposed/canonical/facts/decisions/failures/successes)
 * proposed_by — identifier of the agent or system that proposed this memory (e.g. "agent:{uuid}")
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->string('tier', 20)->default('working')->after('content_hash');
            $table->string('proposed_by', 100)->nullable()->after('tier');
            $table->string('category', 50)->nullable()->after('proposed_by');

            // Partial index to quickly surface unreviewed proposed memories per team
            $table->index(['team_id', 'tier'], 'memories_team_tier_idx');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropIndex('memories_team_tier_idx');
            $table->dropColumn(['tier', 'proposed_by', 'category']);
        });
    }
};
