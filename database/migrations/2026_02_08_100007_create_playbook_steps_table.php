<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playbook_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained();
            $table->foreignUuid('skill_id')->nullable()->constrained();
            $table->integer('order');
            $table->string('execution_mode')->default('sequential');
            $table->string('group_id')->nullable();
            $table->jsonb('conditions')->default('{}');
            $table->jsonb('input_mapping')->default('{}');
            $table->jsonb('output')->nullable();
            $table->string('status')->default('pending');
            $table->integer('duration_ms')->nullable();
            $table->integer('cost_credits')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['experiment_id', 'order']);
            $table->index(['experiment_id', 'status']);
            $table->index(['experiment_id', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playbook_steps');
    }
};
