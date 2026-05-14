<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds review-workflow columns to memories so the Proposed tier can hold
 * decision state (pending/approved/rejected) plus an audit trail. Backward
 * compatible: existing rows keep proposal_status NULL → treated as legacy
 * auto-approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->string('proposal_status', 16)->nullable()->after('proposed_by');
            $table->timestamp('reviewed_at')->nullable()->after('proposal_status');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
            $table->string('reviewed_by', 120)->nullable()->after('rejection_reason');

            $table->index('proposal_status', 'memories_proposal_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropIndex('memories_proposal_status_idx');
            $table->dropColumn(['proposal_status', 'reviewed_at', 'rejection_reason', 'reviewed_by']);
        });
    }
};
