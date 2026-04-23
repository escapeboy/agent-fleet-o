<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_chat_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('external_agent_id')->nullable()->constrained('external_agents')->cascadeOnDelete();
            $table->string('session_token', 128);
            $table->timestampTz('last_activity_at')->useCurrent();
            $table->integer('message_count')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['team_id', 'session_token']);
            $table->index('last_activity_at');
            $table->index(['agent_id', 'last_activity_at']);
            $table->index(['external_agent_id', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_chat_sessions');
    }
};
