<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_nodes', function (Blueprint $table) {
            $table->foreignUuid('compensation_node_id')
                ->nullable()
                ->constrained('workflow_nodes')
                ->nullOnDelete()
                ->after('config');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_nodes', function (Blueprint $table) {
            $table->dropForeign(['compensation_node_id']);
            $table->dropColumn('compensation_node_id');
        });
    }
};
