<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds per-team plugin state support.
 *
 * - team_id = NULL  → global platform record (self-hosted, unchanged)
 * - team_id = uuid  → per-team override (cloud: team opts-in or opts-out)
 *
 * The existing plugin_id unique index is replaced with a composite
 * (team_id, plugin_id) unique so global and per-team rows can coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugin_states', function (Blueprint $table) {
            // Drop the existing single-column unique before adding the composite
            $table->dropUnique(['plugin_id']);

            $table->foreignUuid('team_id')
                ->nullable()
                ->after('id')
                ->constrained('teams')
                ->cascadeOnDelete();

            // Composite unique: one row per (team, plugin) pair;
            // global rows have team_id = NULL which satisfies the constraint
            // because NULL != NULL in SQL unique indexes (PostgreSQL behaviour).
            $table->unique(['team_id', 'plugin_id']);
        });
    }

    public function down(): void
    {
        Schema::table('plugin_states', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'plugin_id']);
            $table->dropConstrainedForeignId('team_id');
            $table->unique(['plugin_id']);
        });
    }
};
