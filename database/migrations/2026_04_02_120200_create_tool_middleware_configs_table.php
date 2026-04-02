<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_middleware_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tool_id')->constrained()->cascadeOnDelete();
            $table->string('middleware_class');
            $table->string('label');
            $table->jsonb('config')->default('{}');
            $table->integer('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tool_id', 'enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_middleware_configs');
    }
};
