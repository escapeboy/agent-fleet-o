<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('context_git_syncs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();
            $table->string('branch')->default('fleetq-context');
            $table->boolean('sync_artifacts')->default(true);
            $table->boolean('sync_memory')->default(true);
            $table->string('artifact_path_prefix')->default('artifacts/');
            $table->string('memory_path_prefix')->default('memory/');
            $table->string('last_pushed_sha')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('context_git_syncs');
    }
};
