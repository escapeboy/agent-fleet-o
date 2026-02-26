<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('channel');                      // 'telegram', 'whatsapp', 'discord', 'signal_protocol', 'matrix'
            $table->string('external_id');                  // channel-native user/sender identifier
            $table->string('external_name')->nullable();    // display name from channel
            $table->string('status');                       // pending | approved | blocked
            $table->string('pairing_code', 8)->nullable();
            $table->timestamp('pairing_code_expires_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();            // platform-specific extras (phone, username, etc.)
            $table->timestamps();

            $table->unique(['team_id', 'channel', 'external_id']);
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_bindings');
    }
};
