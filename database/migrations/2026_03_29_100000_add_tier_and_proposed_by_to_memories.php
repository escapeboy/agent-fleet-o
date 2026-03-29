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
        // tier, proposed_by, category columns already exist from earlier migrations.
        // Only add the compound index which did not exist before.
        Schema::table('memories', function (Blueprint $table) {
            $table->index(['team_id', 'tier'], 'memories_team_tier_idx');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropIndex('memories_team_tier_idx');
        });
    }
};
