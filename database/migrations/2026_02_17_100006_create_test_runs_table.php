<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('test_suite_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->jsonb('results')->nullable();
            $table->float('score')->nullable();
            $table->jsonb('agent_feedback')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['test_suite_id', 'status']);
            $table->index(['experiment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_runs');
    }
};
