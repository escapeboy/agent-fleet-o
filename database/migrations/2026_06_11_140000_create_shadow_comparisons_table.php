<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shadow_comparisons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose')->nullable();
            $table->string('prompt_hash', 64);

            $table->string('primary_provider');
            $table->string('primary_model');
            $table->integer('primary_latency_ms');
            $table->integer('primary_cost_credits');
            $table->string('primary_output_hash', 64);
            $table->integer('primary_output_chars');

            $table->string('shadow_provider');
            $table->string('shadow_model');
            $table->string('shadow_status'); // completed | failed
            $table->integer('shadow_latency_ms')->nullable();
            $table->integer('shadow_cost_credits')->nullable();
            $table->string('shadow_output_hash', 64)->nullable();
            $table->integer('shadow_output_chars')->nullable();
            $table->text('shadow_error')->nullable();

            $table->boolean('outputs_match')->nullable();

            // Optional, off by default (PII/storage) — gated by config store_snippets
            $table->text('primary_snippet')->nullable();
            $table->text('shadow_snippet')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index('shadow_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_comparisons');
    }
};
