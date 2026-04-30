<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_chat_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained('agent_chat_sessions')->cascadeOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('external_agent_id')->nullable()->constrained('external_agents')->nullOnDelete();
            $table->string('direction', 16);
            $table->string('message_type', 32);
            $table->uuid('msg_id');
            $table->uuid('in_reply_to')->nullable();
            $table->string('from_identifier');
            $table->string('to_identifier');
            $table->string('status', 24);
            $table->jsonb('payload');
            $table->text('error')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->timestampsTz();

            $table->unique('msg_id');
            $table->index(['team_id', 'session_id', 'created_at']);
            $table->index(['agent_id', 'direction']);
            $table->index(['external_agent_id', 'direction']);
            $table->index('status');
        });

        if (config('database.default') === 'pgsql') {
            DB::statement('CREATE INDEX acm_payload_gin ON agent_chat_messages USING GIN (payload)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_chat_messages');
    }
};
