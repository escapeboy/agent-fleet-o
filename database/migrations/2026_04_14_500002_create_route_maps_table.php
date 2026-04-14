<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_maps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('project', 100);
            $table->string('release', 100)->nullable();
            $table->jsonb('routes');
            $table->timestamps();

            $table->unique(['team_id', 'project']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_maps');
    }
};
