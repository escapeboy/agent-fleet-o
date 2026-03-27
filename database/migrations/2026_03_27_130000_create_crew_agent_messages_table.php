<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_agent_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('crew_execution_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sender_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignUuid('recipient_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignUuid('parent_message_id')->nullable()->constrained('crew_agent_messages')->nullOnDelete();
            // finding|challenge|query|broadcast|system
            $table->string('message_type');
            $table->integer('round')->default(0);
            $table->text('content');
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['crew_execution_id', 'round']);
            $table->index(['crew_execution_id', 'recipient_agent_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_agent_messages');
    }
};
