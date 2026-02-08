<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experiment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->jsonb('input')->default('{}');
            $table->jsonb('output')->nullable();
            $table->jsonb('skills_executed')->default('[]');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->integer('cost_credits')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index(['agent_id', 'status']);
            $table->index(['experiment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_executions');
    }
};
