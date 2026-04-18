<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->jsonb('ui_artifacts')->nullable();
        });

        Schema::create('assistant_ui_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('assistant_message_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->integer('schema_version')->default(1);
            $table->jsonb('payload');
            $table->string('source_tool', 64)->nullable();
            $table->integer('size_bytes');
            $table->timestamp('rendered_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['team_id', 'created_at'], 'assistant_ui_artifacts_team_time_idx');
            $table->index('type', 'assistant_ui_artifacts_type_idx');
            $table->index('assistant_message_id', 'assistant_ui_artifacts_msg_idx');
        });

        // GIN index for JSONB payload search (PostgreSQL only; sqlite test driver silently ignores)
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE INDEX assistant_ui_artifacts_payload_gin_idx ON assistant_ui_artifacts USING gin (payload)',
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS assistant_ui_artifacts_payload_gin_idx');
        }

        Schema::dropIfExists('assistant_ui_artifacts');

        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropColumn('ui_artifacts');
        });
    }
};
