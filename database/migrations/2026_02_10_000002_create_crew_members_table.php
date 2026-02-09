<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('crew_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('worker');
            $table->integer('sort_order')->default(0);
            $table->jsonb('config')->default('{}');
            $table->timestamps();

            $table->unique(['crew_id', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_members');
    }
};
