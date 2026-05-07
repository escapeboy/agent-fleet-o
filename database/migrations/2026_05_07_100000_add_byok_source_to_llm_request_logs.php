<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_request_logs', function (Blueprint $table): void {
            $table->string('byok_source', 20)->nullable()->after('provider');
            $table->index('byok_source', 'llm_request_logs_byok_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('llm_request_logs', function (Blueprint $table): void {
            $table->dropIndex('llm_request_logs_byok_source_idx');
            $table->dropColumn('byok_source');
        });
    }
};
