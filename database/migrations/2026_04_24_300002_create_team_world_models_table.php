<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_world_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('digest')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->jsonb('stats')->default('{}');
            $table->timestampTz('generated_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_world_models');
    }
};
