<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->boolean('ragflow_enabled')->default(false)->after('status');
            $table->string('ragflow_dataset_id', 100)->nullable()->after('ragflow_enabled');
            $table->string('ragflow_chunk_method', 50)->nullable()->after('ragflow_dataset_id');
            $table->timestamp('ragflow_last_synced_at')->nullable()->after('ragflow_chunk_method');
        });

        DB::statement(
            'CREATE INDEX idx_kb_ragflow ON knowledge_bases (team_id) WHERE ragflow_enabled = true',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_kb_ragflow');

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->dropColumn(['ragflow_enabled', 'ragflow_dataset_id', 'ragflow_chunk_method', 'ragflow_last_synced_at']);
        });
    }
};
