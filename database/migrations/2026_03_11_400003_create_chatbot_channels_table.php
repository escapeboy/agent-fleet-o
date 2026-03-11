<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('chatbot_id')->constrained()->cascadeOnDelete();
            $table->string('channel_type'); // web_widget|api|telegram|slack
            $table->jsonb('config')->default('{}'); // channel-specific config (bot_token, webhook_url, etc.)
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::table('chatbot_channels', function (Blueprint $table) {
            $table->index(['chatbot_id', 'channel_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_channels');
    }
};
