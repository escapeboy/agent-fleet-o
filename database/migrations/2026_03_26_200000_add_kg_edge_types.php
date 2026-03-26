<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add source_node_type, target_node_type, and edge_type columns to kg_edges.
     *
     * These columns support MiniRAG-style heterogeneous graph nodes:
     *   - node_type: 'entity' (default) or 'chunk' (memory record acting as a node)
     *   - edge_type: 'relates_to' (default), 'contains', 'co_occurs', 'similar'
     *
     * Defaults ensure backward compatibility — all existing edges remain valid.
     */
    public function up(): void
    {
        Schema::table('kg_edges', function (Blueprint $table) {
            // 'entity' | 'chunk'
            $table->string('source_node_type', 20)->default('entity')->after('source_entity_id');
            // 'entity' | 'chunk'
            $table->string('target_node_type', 20)->default('entity')->after('target_entity_id');
            // 'relates_to' | 'contains' | 'co_occurs' | 'similar'
            $table->string('edge_type', 30)->default('relates_to')->after('relation_type');
        });
    }

    public function down(): void
    {
        Schema::table('kg_edges', function (Blueprint $table) {
            $table->dropColumn(['source_node_type', 'target_node_type', 'edge_type']);
        });
    }
};
