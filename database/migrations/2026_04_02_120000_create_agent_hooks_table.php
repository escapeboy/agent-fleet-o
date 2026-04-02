<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_hooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('position');
            $table->string('type');
            $table->jsonb('config')->default('{}');
            $table->integer('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'agent_id', 'position', 'enabled']);
            $table->index(['team_id', 'position', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_hooks');
    }
};
