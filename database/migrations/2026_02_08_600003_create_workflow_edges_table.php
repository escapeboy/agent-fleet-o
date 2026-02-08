<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('source_node_id')->constrained('workflow_nodes')->cascadeOnDelete();
            $table->foreignUuid('target_node_id')->constrained('workflow_nodes')->cascadeOnDelete();
            $table->jsonb('condition')->nullable();
            $table->string('label', 100)->nullable();
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('workflow_id');
            $table->index('source_node_id');
            $table->index('target_node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_edges');
    }
};
