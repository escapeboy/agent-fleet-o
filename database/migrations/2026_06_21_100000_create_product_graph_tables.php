<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('node_type');
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('planned');
            $table->text('description')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->string('external_ref')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->unique(['team_id', 'node_type', 'slug']);
            $table->index(['team_id', 'node_type']);
            $table->index(['team_id', 'status']);
        });

        Schema::create('product_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('source_node_id')->constrained('product_nodes')->cascadeOnDelete();
            $table->foreignUuid('target_node_id')->constrained('product_nodes')->cascadeOnDelete();
            $table->string('edge_type');
            $table->string('description')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->unique(['team_id', 'source_node_id', 'target_node_id', 'edge_type'], 'product_edges_unique');
            $table->index(['team_id', 'source_node_id']);
            $table->index(['team_id', 'target_node_id']);
        });

        Schema::create('product_graph_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('change_type');
            $table->uuid('target_id')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->string('status')->default('pending');
            $table->string('proposed_by_label')->default('user');
            $table->foreignUuid('proposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note')->nullable();
            $table->uuid('applied_ref_id')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });

        // PostgreSQL-only GIN index on tags for @>/? operators. SQLite (tests) skips it.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX product_nodes_tags_gin_idx ON product_nodes USING gin (tags)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_graph_changes');
        Schema::dropIfExists('product_edges');
        Schema::dropIfExists('product_nodes');
    }
};
