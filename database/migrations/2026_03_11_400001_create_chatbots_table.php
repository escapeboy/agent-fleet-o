<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('type')->default('custom'); // help_bot|support_assistant|developer_assistant|custom
            $table->string('status')->default('draft'); // draft|active|inactive|suspended
            $table->boolean('agent_is_dedicated')->default(true); // true = auto-created, false = user-selected existing
            $table->jsonb('config')->default('{}'); // llm overrides, temperature, max_tokens, etc.
            $table->jsonb('widget_config')->default('{}'); // colors, position, greeting, avatar_url
            $table->decimal('confidence_threshold', 3, 2)->default(0.70);
            $table->boolean('human_escalation_enabled')->default(false);
            $table->string('welcome_message')->nullable();
            $table->string('fallback_message')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::table('chatbots', function (Blueprint $table) {
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbots');
    }
};
