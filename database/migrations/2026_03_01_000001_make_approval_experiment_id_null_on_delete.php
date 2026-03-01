<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change approval_requests.experiment_id FK from cascadeOnDelete to nullOnDelete.
 *
 * Rationale: deleting (archiving) an experiment should NOT delete the corresponding
 * approval requests — those are part of the audit trail and may be referenced by
 * outbound proposals or team reviews. Setting experiment_id to NULL on experiment
 * deletion preserves the approval history while releasing the FK constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            // Drop the existing cascadeOnDelete FK
            $table->dropForeign(['experiment_id']);

            // Make the column nullable (required for nullOnDelete to work)
            $table->uuid('experiment_id')->nullable()->change();

            // Re-add FK with nullOnDelete
            $table->foreign('experiment_id')
                ->references('id')
                ->on('experiments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropForeign(['experiment_id']);
            $table->uuid('experiment_id')->nullable(false)->change();
            $table->foreign('experiment_id')
                ->references('id')
                ->on('experiments')
                ->cascadeOnDelete();
        });
    }
};
