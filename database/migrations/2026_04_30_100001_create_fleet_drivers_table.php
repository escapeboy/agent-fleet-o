<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_drivers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->string('name');
            $table->string('license_number');
            $table->string('status')->default('active');
            $table->jsonb('telemetry_summary')->nullable();
            $table->float('latest_score')->nullable();
            $table->text('score_reasoning')->nullable();
            $table->timestamp('boruna_last_scored_at')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_drivers');
    }
};
