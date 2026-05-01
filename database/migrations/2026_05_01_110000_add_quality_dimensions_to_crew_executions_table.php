<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_executions', function (Blueprint $table): void {
            $table->jsonb('quality_dimensions')->nullable()->after('quality_score');
        });
    }

    public function down(): void
    {
        Schema::table('crew_executions', function (Blueprint $table): void {
            $table->dropColumn('quality_dimensions');
        });
    }
};
