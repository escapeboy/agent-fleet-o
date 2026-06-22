<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_transcripts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('simulation_runs')->cascadeOnDelete();
            $table->foreignUuid('persona_id')->constrained('simulation_personas')->cascadeOnDelete();
            $table->jsonb('turns')->nullable();
            $table->jsonb('scores')->nullable();
            $table->string('verdict')->nullable();
            $table->unsignedSmallInteger('failed_turn_index')->nullable();
            $table->timestamps();

            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_transcripts');
    }
};
