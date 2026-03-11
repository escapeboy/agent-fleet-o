<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('chatbot_sessions')->cascadeOnDelete();
            $table->foreignUuid('chatbot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('user'); // user|assistant|system
            $table->text('content')->nullable(); // final content (set after approval if escalated)
            $table->text('draft_content')->nullable(); // pending escalation content
            $table->decimal('confidence', 5, 4)->nullable(); // 0.0000 - 1.0000
            $table->integer('latency_ms')->nullable();
            $table->boolean('was_escalated')->default(false);
            $table->string('feedback')->nullable(); // thumbs_up|thumbs_down
            $table->jsonb('metadata')->default('{}'); // sources, tool_calls, etc.
            $table->timestampsTz();
        });

        Schema::table('chatbot_messages', function (Blueprint $table) {
            $table->index(['session_id', 'created_at']);
            $table->index(['chatbot_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_messages');
    }
};
