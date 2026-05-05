<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->string('title');
            $table->text('raw_text');
            $table->string('severity')->nullable();
            $table->string('category')->nullable();
            $table->boolean('regulator_reportable')->default(false);
            $table->text('classification_reasoning')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['team_id', 'severity']);
            $table->index(['team_id', 'regulator_reportable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_incidents');
    }
};
