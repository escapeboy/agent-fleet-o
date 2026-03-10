<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company intent scores table for the Signal Stacking Engine.
 *
 * Aggregates signals from multiple sources per entity (company/person)
 * into a composite FIRE score (Fit + Intent + Engagement + Relationship).
 *
 * Updated by RecalculateIntentScoreJob whenever a new signal arrives
 * for a known entity (debounced to once per 15 minutes per entity).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_intent_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();

            // Entity identification — domain, LinkedIn URL, or internal contact_identity_id
            $table->string('entity_key', 500);
            $table->string('entity_type', 50); // 'company' | 'person'

            // FIRE model dimensions (0–100 each)
            $table->float('composite_score')->default(0);
            $table->float('fit_score')->default(0);         // ICP firmographic match
            $table->float('intent_score')->default(0);      // research / buying behaviour signals
            $table->float('engagement_score')->default(0);  // direct engagement with your content
            $table->float('relationship_score')->default(0); // existing relationship depth

            // Signal aggregation counters
            $table->unsignedInteger('signal_count')->default(0);
            $table->unsignedInteger('signal_diversity')->default(0); // distinct source_type count

            // Full breakdown for MCP tool and debugging
            $table->jsonb('score_breakdown')->default('{}');

            // Scoring control
            $table->timestampTz('last_scored_at')->nullable();
            $table->timestampTz('recalculate_after')->nullable(); // debounce: skip recalculation before this

            $table->timestampsTz();
        });

        // Unique per team + entity (prevents duplicate rows, used for upsert)
        DB::statement('
            CREATE UNIQUE INDEX idx_intent_scores_team_entity
            ON company_intent_scores (team_id, entity_key, entity_type)
        ');

        // Fast lookup by score for hot-lead queries (partial — only meaningful scores)
        DB::statement('
            CREATE INDEX idx_intent_scores_composite
            ON company_intent_scores (team_id, composite_score DESC)
            WHERE composite_score > 20
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_intent_scores');
    }
};
