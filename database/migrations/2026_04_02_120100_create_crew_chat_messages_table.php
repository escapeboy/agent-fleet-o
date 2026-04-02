<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('crew_execution_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agent_name')->nullable();
            $table->string('role')->default('assistant');
            $table->text('content');
            $table->integer('round')->default(1);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->index(['crew_execution_id', 'round', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_chat_messages');
    }
};
