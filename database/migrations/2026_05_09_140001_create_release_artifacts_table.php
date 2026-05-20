<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('release_id');
            $table->uuid('artifact_id');
            $table->integer('artifact_version');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('release_id')->references('id')->on('releases')->cascadeOnDelete();
            $table->foreign('artifact_id')->references('id')->on('artifacts')->cascadeOnDelete();
            $table->unique(['release_id', 'artifact_id']);
            $table->index(['release_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_artifacts');
    }
};
