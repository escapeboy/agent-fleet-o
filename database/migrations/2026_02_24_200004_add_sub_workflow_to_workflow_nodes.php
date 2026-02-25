<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_nodes', function (Blueprint $table) {
            $table->foreignUuid('sub_workflow_id')
                ->nullable()
                ->after('crew_id')
                ->constrained('workflows')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_nodes', function (Blueprint $table) {
            $table->dropForeign(['sub_workflow_id']);
            $table->dropColumn('sub_workflow_id');
        });
    }
};
