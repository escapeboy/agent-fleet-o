<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bridge_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 64)->unique();
            $table->string('status', 32)->default('connected');
            $table->string('bridge_version', 32)->nullable();
            $table->jsonb('endpoints')->default('{}'); // LLMs, agents, MCP servers manifest
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestampsTz();
            $table->timestampTz('connected_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('disconnected_at')->nullable();
        });

        Schema::table('bridge_connections', function (Blueprint $table) {
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_connections');
    }
};
