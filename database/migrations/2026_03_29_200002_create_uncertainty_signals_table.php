<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uncertainty_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('experiment_stage_id')->nullable()->constrained('experiment_stages')->nullOnDelete();
            $table->text('signal_text');
            $table->jsonb('context')->nullable();
            $table->string('status')->default('pending');
            $table->integer('ttl_minutes')->default(30);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
        });

        DB::statement(
            "CREATE INDEX uncertainty_signals_team_pending_idx ON uncertainty_signals (team_id, status) WHERE status = 'pending'",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('uncertainty_signals');
    }
};
