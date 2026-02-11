<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outbound_proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending|approved|rejected|expired
            $table->text('rejection_reason')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['experiment_id', 'status']);
            // Partial index for pending approvals (PostgreSQL only)
            if (DB::getDriverName() === 'pgsql') {
                $table->rawIndex(
                    "(status = 'pending')",
                    'approval_requests_pending_idx'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
