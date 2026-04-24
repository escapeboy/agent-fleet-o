<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->boolean('chat_protocol_enabled')->default(false);
            $table->string('chat_protocol_visibility', 24)->default('private');
            $table->string('chat_protocol_slug')->nullable();
            $table->jsonb('chat_protocol_config')->nullable();
            $table->string('chat_protocol_secret')->nullable();
        });

        Schema::table('agents', function (Blueprint $table): void {
            $table->unique('chat_protocol_slug');
            $table->index('chat_protocol_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->dropIndex(['chat_protocol_enabled']);
            $table->dropUnique(['chat_protocol_slug']);
            $table->dropColumn([
                'chat_protocol_enabled',
                'chat_protocol_visibility',
                'chat_protocol_slug',
                'chat_protocol_config',
                'chat_protocol_secret',
            ]);
        });
    }
};
