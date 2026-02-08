<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('skill_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('label');
            $table->integer('position_x')->default(0);
            $table->integer('position_y')->default(0);
            $table->jsonb('config')->default('{}');
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index(['workflow_id', 'order']);
            $table->index(['workflow_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_nodes');
    }
};
