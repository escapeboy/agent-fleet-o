<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_evals', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // One eval invocation. Every row a `jev:eval` run writes shares it, and
            // `jev:report` aggregates by it.
            $table->uuid('run_id');
            $table->string('driver', 64);
            // The model id the driver REPORTED, not the one we asked for — an alias
            // resolves server-side, and the report has to attribute answers to the
            // version that actually produced them.
            $table->string('model', 128);
            $table->string('dataset', 255);
            $table->string('case_id', 255);
            $table->string('question_id', 128);
            $table->string('split', 16);
            $table->string('lang', 16)->nullable();

            $table->jsonb('answer');
            $table->jsonb('probabilities')->nullable();
            $table->float('confidence')->nullable();
            $table->jsonb('gold');
            $table->boolean('correct');

            $table->integer('latency_ms');
            $table->integer('input_tokens');

            // --repeat=N sends the identical request N times; the index separates the
            // repeats so the determinism column can take a stddev across them.
            $table->unsignedSmallInteger('repeat_index')->default(0);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['run_id', 'driver']);
            $table->index(['dataset', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_evals');
    }
};
