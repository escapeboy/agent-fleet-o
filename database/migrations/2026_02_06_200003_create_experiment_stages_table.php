<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experiment_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->string('stage'); // scoring|planning|building|executing|collecting_metrics|evaluating
            $table->integer('iteration')->default(1);
            $table->string('status')->default('pending'); // pending|running|completed|failed|skipped
            $table->jsonb('input_snapshot')->nullable();
            $table->jsonb('output_snapshot')->nullable();
            $table->integer('retry_count')->default(0);
            $table->integer('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['experiment_id', 'stage', 'iteration']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiment_stages');
    }
};
