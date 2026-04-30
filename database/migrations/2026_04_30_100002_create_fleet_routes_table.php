<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->string('name');
            $table->string('origin');
            $table->string('destination');
            $table->float('risk_score')->nullable();
            $table->string('approval_status')->default('pending');
            $table->uuid('approval_decision_id')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['team_id', 'approval_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_routes');
    }
};
