<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_personas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('suite_id')->constrained('simulation_suites')->cascadeOnDelete();
            $table->string('name');
            $table->text('profile')->nullable();
            $table->text('goal')->nullable();
            $table->jsonb('adversarial_tags')->nullable();
            $table->text('seed_message')->nullable();
            $table->timestamps();

            $table->index('suite_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_personas');
    }
};
