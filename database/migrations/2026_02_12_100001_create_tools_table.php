<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('type');
            $table->string('status')->default('active');
            $table->jsonb('transport_config')->default('{}');
            $table->jsonb('credentials')->default('{}');
            $table->jsonb('tool_definitions')->default('[]');
            $table->jsonb('settings')->default('{}');
            $table->timestamp('last_health_check')->nullable();
            $table->string('health_status')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'slug']);
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tools');
    }
};
