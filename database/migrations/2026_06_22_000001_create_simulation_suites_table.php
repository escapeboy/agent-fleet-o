<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_suites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('target_type')->default('agent');
            $table->uuid('target_id');
            $table->text('brief')->nullable();
            $table->jsonb('criteria')->nullable();
            $table->unsignedSmallInteger('persona_count')->default(8);
            $table->unsignedSmallInteger('max_turns')->default(6);
            $table->decimal('pass_threshold', 4, 2)->default(6.0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_suites');
    }
};
