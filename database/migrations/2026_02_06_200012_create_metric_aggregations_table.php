<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_aggregations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->string('metric_type');
            $table->string('period'); // hourly|daily
            $table->timestamp('period_start');
            $table->float('sum_value')->default(0);
            $table->integer('count')->default(0);
            $table->float('avg_value')->default(0);
            $table->jsonb('breakdown')->nullable();
            $table->timestamps();

            $table->unique(['experiment_id', 'metric_type', 'period', 'period_start'], 'metric_agg_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_aggregations');
    }
};
