<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->text('bot_token');         // encrypted in model
            $table->string('bot_username')->nullable();
            $table->string('bot_name')->nullable();
            $table->enum('routing_mode', ['assistant', 'project', 'trigger_rules'])->default('assistant');
            $table->foreignUuid('default_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->boolean('webhook_mode')->default(false);
            $table->string('webhook_secret')->nullable();
            $table->enum('status', ['active', 'paused', 'error'])->default('active');
            $table->string('last_error')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique('team_id'); // one bot per team
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bots');
    }
};
