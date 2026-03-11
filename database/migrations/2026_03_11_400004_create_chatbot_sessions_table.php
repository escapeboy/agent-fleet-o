<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('chatbot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('channel')->default('api'); // web_widget|api|telegram|slack
            $table->string('external_user_id')->nullable(); // e.g. Telegram user id
            $table->string('ip_address', 45)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->integer('message_count')->default(0);
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('last_activity_at')->nullable();
            $table->timestampsTz();
        });

        Schema::table('chatbot_sessions', function (Blueprint $table) {
            $table->index(['chatbot_id', 'created_at']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_sessions');
    }
};
