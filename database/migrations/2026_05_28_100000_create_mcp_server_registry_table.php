<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_server_registry', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // 'mcp_stdio' or 'mcp_http' — mirrors ToolType values
            $table->string('transport');

            // For mcp_stdio: { command, args[], env{} }
            // For mcp_http:  { url, bearer_token? } (token stored encrypted by app layer when set)
            $table->jsonb('connection')->default('{}');

            // 'platform_trusted' | 'verified' | 'community'
            $table->string('trust_level')->default('community');

            $table->boolean('is_active')->default(true);

            // Allowlist of MCP tool names exposed by this server. Empty/null = expose all.
            $table->jsonb('tool_allowlist')->nullable();

            // Free-form policy hints (rate limits, regional restrictions, etc).
            $table->jsonb('policy_rules')->default('{}');

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('trust_level');
            $table->index('is_active');
            $table->index('connection', 'mcp_server_registry_connection_gin_idx', 'gin');
        });

        Schema::table('tools', function (Blueprint $table) {
            $table->foreignUuid('registry_server_id')
                ->nullable()
                ->after('credential_id')
                ->constrained('mcp_server_registry')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registry_server_id');
        });

        Schema::dropIfExists('mcp_server_registry');
    }
};
