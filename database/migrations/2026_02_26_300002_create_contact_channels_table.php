<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('contact_identity_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('channel');                         // 'telegram', 'whatsapp', 'slack', 'discord', etc.
            $table->string('external_id');                     // channel-native sender identifier
            $table->string('external_username')->nullable();   // human-readable handle / username
            $table->boolean('verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'channel', 'external_id']);
            $table->index(['team_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_channels');
    }
};
