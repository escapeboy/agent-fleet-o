<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255)->nullable();
            $table->string('context_type', 100)->nullable();
            $table->uuid('context_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampsTz();

            $table->index(['team_id', 'user_id', 'last_message_at']);
            $table->index(['context_type', 'context_id']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->jsonb('tool_calls')->nullable();
            $table->jsonb('tool_results')->nullable();
            $table->jsonb('token_usage')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at');

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
