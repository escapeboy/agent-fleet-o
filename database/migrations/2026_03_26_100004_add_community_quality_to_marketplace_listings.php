<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table) {
            $table->float('community_quality_score')->default(0.0)->after('usage_trend');
            $table->float('install_success_rate')->default(0.0)->after('community_quality_score');
            $table->float('community_reliability_rate')->default(0.0)->after('install_success_rate');
            $table->unsignedBigInteger('effective_run_count')->default(0)->after('community_reliability_rate');
            $table->timestamp('quality_computed_at')->nullable()->after('effective_run_count');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table) {
            $table->dropColumn([
                'community_quality_score',
                'install_success_rate',
                'community_reliability_rate',
                'effective_run_count',
                'quality_computed_at',
            ]);
        });
    }
};
