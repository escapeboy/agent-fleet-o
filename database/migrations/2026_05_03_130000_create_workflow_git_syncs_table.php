<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_git_syncs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->foreignUuid('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained();
            $table->string('branch')->default('fleetq-sync');
            $table->string('path_prefix')->default('workflows/');
            $table->string('last_pushed_sha')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_id']);
            $table->index(['team_id', 'git_repository_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_git_syncs');
    }
};
