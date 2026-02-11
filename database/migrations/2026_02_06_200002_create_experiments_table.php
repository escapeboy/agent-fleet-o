<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experiments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('thesis')->nullable();
            $table->string('track'); // growth|retention|revenue|engagement
            $table->string('status')->default('draft');
            $table->string('paused_from_status')->nullable();
            $table->jsonb('constraints')->nullable();
            $table->jsonb('success_criteria')->nullable();
            $table->integer('budget_cap_credits')->default(0);
            $table->integer('budget_spent_credits')->default(0);
            $table->integer('max_iterations')->default(10);
            $table->integer('current_iteration')->default(0);
            $table->integer('max_outbound_count')->default(100);
            $table->integer('outbound_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('killed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('track');
            $table->index('user_id');
            // Partial index for active experiments (PostgreSQL only)
            if (DB::getDriverName() === 'pgsql') {
                $table->rawIndex(
                    "((status NOT IN ('completed', 'killed', 'discarded', 'expired')))",
                    'experiments_active_idx'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiments');
    }
};
