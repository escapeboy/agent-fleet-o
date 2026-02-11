<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('frequency', 20)->default('daily');
            $table->string('cron_expression', 100)->nullable();
            $table->integer('interval_minutes')->nullable();
            $table->string('timezone', 50)->default('UTC');
            $table->string('overlap_policy', 20)->default('skip');
            $table->integer('max_consecutive_failures')->default(3);
            $table->boolean('catchup_missed')->default(false);
            $table->boolean('run_immediately')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_schedules');
    }
};
