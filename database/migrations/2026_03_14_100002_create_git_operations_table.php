<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('git_repository_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('experiment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation_type');
            $table->string('status')->default('pending');
            $table->jsonb('payload')->default('{}');
            $table->jsonb('result')->default('{}');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at');

            $table->index(['git_repository_id', 'created_at']);
            $table->index(['git_repository_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_operations');
    }
};
