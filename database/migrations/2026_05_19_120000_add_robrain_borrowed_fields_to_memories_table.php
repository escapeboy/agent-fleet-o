<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the RoBrain-inspired fields to the memories table:
 *
 *  - rejected_alternatives: structured [{option, reason}] vetoes — the road
 *    not taken, queryable instead of buried in prose.
 *  - supersedes_id: self-referential link to the memory this one replaces,
 *    building a temporal belief graph (history stays queryable).
 *  - conflict_flag / conflict_with_id / conflict_detected_at: cross-corpus
 *    contradiction detection — pairs of memories that reverse each other,
 *    flagged for human review.
 *
 * All columns are SQLite-portable (no FK constraints, no partial indexes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->jsonb('rejected_alternatives')->nullable()->after('why_it_matters');
            $table->uuid('supersedes_id')->nullable()->after('belief_status');
            $table->boolean('conflict_flag')->default(false)->after('supersedes_id');
            $table->uuid('conflict_with_id')->nullable()->after('conflict_flag');
            $table->timestamp('conflict_detected_at')->nullable()->after('conflict_with_id');

            $table->index('supersedes_id', 'memories_supersedes_id_idx');
            $table->index(['team_id', 'conflict_flag'], 'memories_team_conflict_idx');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropIndex('memories_supersedes_id_idx');
            $table->dropIndex('memories_team_conflict_idx');

            $table->dropColumn([
                'rejected_alternatives',
                'supersedes_id',
                'conflict_flag',
                'conflict_with_id',
                'conflict_detected_at',
            ]);
        });
    }
};
