<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agentic AI Flywheel #3 — error-mode catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_modes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->string('slug', 160);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('lever', 32)->default('unassigned');
            $table->string('status', 16)->default('open');
            $table->unsignedInteger('occurrence_count')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->jsonb('example_trace_ids')->default('[]');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->unique(['team_id', 'slug']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_modes');
    }
};
