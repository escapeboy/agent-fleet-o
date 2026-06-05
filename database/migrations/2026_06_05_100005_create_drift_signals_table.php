<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agentic AI Flywheel #4 — drift signal time series.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drift_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->string('signal_type', 32);
            $table->double('value')->nullable();
            $table->double('baseline')->nullable();
            $table->boolean('breached')->default(false);
            $table->string('window', 16)->nullable();
            $table->timestamp('detected_at');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->index(['team_id', 'signal_type', 'detected_at'], 'drift_signals_team_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drift_signals');
    }
};
