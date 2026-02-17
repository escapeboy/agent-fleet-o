<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_suites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('test_agent_count')->default(1);
            $table->string('test_strategy')->default('full');
            $table->jsonb('assertion_rules')->nullable();
            $table->float('quality_threshold')->default(0.7);
            $table->timestamp('last_run_at')->nullable();
            $table->float('pass_rate')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['project_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_suites');
    }
};
