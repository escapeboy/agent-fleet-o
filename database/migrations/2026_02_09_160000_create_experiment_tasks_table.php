<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experiment_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->string('stage'); // 'building' (extensible later)
            $table->string('batch_id')->nullable(); // Laravel Bus batch ID
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type'); // research, code, design, seo, strategy, plan, config
            $table->string('status')->default('pending'); // ExperimentTaskStatus enum
            $table->foreignUuid('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->jsonb('input_data')->nullable();
            $table->jsonb('output_data')->nullable();
            $table->text('error')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['experiment_id', 'stage']);
            $table->index(['experiment_id', 'status']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiment_tasks');
    }
};
