<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_skill', function (Blueprint $table) {
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->integer('priority')->default(0);
            $table->jsonb('overrides')->default('{}');
            $table->timestamps();

            $table->primary(['agent_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_skill');
    }
};
