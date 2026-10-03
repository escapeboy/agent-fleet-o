<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP definition pinning: a tools/list change after approval is held here until
 * a person approves it (ToolDefinitionPinner).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->jsonb('pending_tool_definitions')->nullable();
            $table->string('pending_definitions_hash', 64)->nullable();
            $table->timestampTz('pending_definitions_detected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->dropColumn(['pending_tool_definitions', 'pending_definitions_hash', 'pending_definitions_detected_at']);
        });
    }
};
