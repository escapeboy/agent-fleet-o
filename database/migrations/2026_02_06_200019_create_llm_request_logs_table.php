<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_request_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('idempotency_key')->unique();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experiment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('experiment_stage_id')->nullable()->constrained('experiment_stages')->nullOnDelete();
            $table->string('provider');
            $table->string('model');
            $table->string('prompt_hash', 32)->nullable(); // xxh128
            $table->string('status')->default('pending'); // pending|completed|failed
            $table->jsonb('response_body')->nullable();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('cost_credits')->default(0);
            $table->integer('latency_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
            $table->index('prompt_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_request_logs');
    }
};
