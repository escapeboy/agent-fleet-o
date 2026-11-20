<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable snapshots of a workflow's graph, written each time an active
 * workflow's graph is replaced (and its version bumped). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('graph')->default('{}');
            $table->uuid('restored_from_version_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['workflow_id', 'version']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_versions');
    }
};
