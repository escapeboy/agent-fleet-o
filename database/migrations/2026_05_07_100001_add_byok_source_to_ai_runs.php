<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->string('byok_source', 20)->nullable()->after('provider');
            $table->index('byok_source', 'ai_runs_byok_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropIndex('ai_runs_byok_source_idx');
            $table->dropColumn('byok_source');
        });
    }
};
