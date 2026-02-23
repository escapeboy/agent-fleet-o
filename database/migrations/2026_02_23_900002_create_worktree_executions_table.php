<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worktree_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('skill_execution_id')->constrained('skill_executions')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('repo_path');
            $table->string('worktree_path');
            $table->string('branch_name');
            $table->string('base_commit_sha', 40)->nullable();
            $table->string('result_commit_sha', 40)->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('stdout')->nullable();
            $table->text('stderr')->nullable();
            $table->string('status')->default('pending'); // pending|running|completed|failed|pending_approval|approved|rejected|cleaned_up
            $table->text('diff')->nullable();
            $table->foreignUuid('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index('skill_execution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worktree_executions');
    }
};
