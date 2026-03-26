<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->unsignedBigInteger('applied_count')->default(0)->after('avg_latency_ms');
            $table->unsignedBigInteger('completed_count')->default(0)->after('applied_count');
            $table->unsignedBigInteger('effective_count')->default(0)->after('completed_count');
            $table->unsignedBigInteger('fallback_count')->default(0)->after('effective_count');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn(['applied_count', 'completed_count', 'effective_count', 'fallback_count']);
        });
    }
};
