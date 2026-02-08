<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('experiment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->jsonb('input')->default('{}');
            $table->jsonb('output')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->integer('cost_credits')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index(['skill_id', 'status']);
            $table->index(['experiment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_executions');
    }
};
