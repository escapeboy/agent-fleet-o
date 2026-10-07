<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('git_commit_provenance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('git_repository_id')->nullable()->constrained('git_repositories')->nullOnDelete();
            $table->string('commit_sha', 64);
            $table->string('branch', 255)->nullable();
            $table->string('source', 32);
            $table->uuid('experiment_id')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->uuid('skill_execution_id')->nullable();
            $table->jsonb('trailers')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'commit_sha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_commit_provenance');
    }
};
