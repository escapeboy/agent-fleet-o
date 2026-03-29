<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_annotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('skill_version_id')->constrained('skill_versions')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('input');
            $table->text('output');
            $table->string('model_id', 100);
            $table->enum('rating', ['good', 'bad']);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['skill_version_id', 'rating']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_annotations');
    }
};
