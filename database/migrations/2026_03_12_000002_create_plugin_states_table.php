<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('plugin_id')->unique();  // Matches FleetPlugin::getId()
            $table->string('name');
            $table->string('version')->default('0.0.0');
            $table->boolean('enabled')->default(true);
            $table->jsonb('settings')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_states');
    }
};
