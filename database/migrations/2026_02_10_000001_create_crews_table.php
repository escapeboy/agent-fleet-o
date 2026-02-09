<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('coordinator_agent_id')->constrained('agents')->nullOnDelete();
            $table->foreignUuid('qa_agent_id')->constrained('agents')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('process_type', 20)->default('hierarchical');
            $table->integer('max_task_iterations')->default(3);
            $table->decimal('quality_threshold', 3, 2)->default(0.70);
            $table->string('status', 20)->default('draft');
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'slug']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crews');
    }
};
