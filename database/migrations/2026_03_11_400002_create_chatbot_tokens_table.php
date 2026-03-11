<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('chatbot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('Default');
            $table->string('token_prefix', 16); // first 8 chars for UI display
            $table->string('token_hash', 64)->unique(); // SHA-256 of full token
            $table->jsonb('allowed_origins')->nullable(); // null = unrestricted
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable(); // set on rotation (48h grace period)
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        Schema::table('chatbot_tokens', function (Blueprint $table) {
            $table->index(['chatbot_id', 'revoked_at']);
            $table->index(['chatbot_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_tokens');
    }
};
