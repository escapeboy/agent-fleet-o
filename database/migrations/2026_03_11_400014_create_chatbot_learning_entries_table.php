<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_learning_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('chatbot_id')->constrained('chatbots')->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained('chatbot_sessions')->cascadeOnDelete();
            $table->foreignUuid('message_id')->constrained('chatbot_messages')->cascadeOnDelete();
            $table->uuid('team_id')->index();
            $table->text('user_message');
            $table->text('original_response');
            $table->text('corrected_response');
            $table->string('reason_code', 30)->nullable(); // factual_error/tone/completeness/hallucination/off_topic/other
            $table->text('operator_notes')->nullable();
            $table->jsonb('model_config')->nullable();
            $table->string('status', 20)->default('pending_review'); // pending_review/accepted/rejected/exported
            $table->timestampTz('exported_at')->nullable();
            $table->timestampsTz();

            $table->index(['chatbot_id', 'status']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_learning_entries');
    }
};
