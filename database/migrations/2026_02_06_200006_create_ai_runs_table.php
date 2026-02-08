<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experiment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('experiment_stage_id')->nullable()->constrained('experiment_stages')->nullOnDelete();
            $table->string('purpose'); // scoring|planning|building|evaluating
            $table->string('provider');
            $table->string('model');
            $table->jsonb('input_schema')->nullable();
            $table->jsonb('prompt_snapshot');
            $table->jsonb('raw_output')->nullable();
            $table->jsonb('parsed_output')->nullable();
            $table->boolean('schema_valid')->nullable();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('cost_credits')->default(0);
            $table->integer('latency_ms')->default(0);
            $table->string('status')->default('pending'); // pending|running|completed|failed|schema_invalid
            $table->timestamps();

            $table->index(['agent_id', 'status']);
            $table->index(['experiment_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
