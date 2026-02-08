<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('type');
            $table->string('execution_type')->default('async');
            $table->string('status')->default('draft');
            $table->string('risk_level')->default('low');
            $table->jsonb('input_schema')->default('{}');
            $table->jsonb('output_schema')->default('{}');
            $table->jsonb('configuration')->default('{}');
            $table->jsonb('cost_profile')->default('{}');
            $table->jsonb('safety_flags')->default('{}');
            $table->string('current_version')->default('1.0.0');
            $table->boolean('requires_approval')->default(false);
            $table->text('system_prompt')->nullable();
            $table->unsignedInteger('execution_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->decimal('avg_latency_ms', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'slug']);
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
