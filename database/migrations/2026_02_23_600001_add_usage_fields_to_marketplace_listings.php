<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('run_count')->default(0)->after('install_count');
            $table->unsignedBigInteger('success_count')->default(0)->after('run_count');
            $table->decimal('avg_cost_credits', 12, 4)->nullable()->after('success_count');
            $table->decimal('avg_duration_ms', 10, 2)->nullable()->after('avg_cost_credits');
            $table->jsonb('usage_trend')->nullable()->after('avg_duration_ms');
            $table->decimal('price_per_run_credits', 10, 4)->default(0)->after('usage_trend');
            $table->boolean('monetization_enabled')->default(false)->after('price_per_run_credits');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table) {
            $table->dropColumn([
                'run_count', 'success_count', 'avg_cost_credits',
                'avg_duration_ms', 'usage_trend',
                'price_per_run_credits', 'monetization_enabled',
            ]);
        });
    }
};
