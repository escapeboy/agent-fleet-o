<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();

            // Source and target code elements for the directed edge
            $table->foreignUuid('source_id')->constrained('code_elements')->cascadeOnDelete();
            $table->foreignUuid('target_id')->constrained('code_elements')->cascadeOnDelete();

            // Edge type: 'calls' | 'imports' | 'inherits'
            $table->string('edge_type', 20);
            $table->timestamps();

            $table->index('source_id');
            $table->index('target_id');
            $table->index(['git_repository_id', 'edge_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_edges');
    }
};
