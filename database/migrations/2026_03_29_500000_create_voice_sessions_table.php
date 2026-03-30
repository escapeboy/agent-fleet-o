<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained('teams');
            $table->foreignUuid('agent_id')->constrained('agents');
            $table->foreignUuid('approval_request_id')->nullable()->constrained('approval_requests');
            $table->foreignUuid('created_by')->constrained('users');
            $table->string('room_name')->unique();
            $table->string('status');
            $table->jsonb('transcript')->default('[]');
            $table->jsonb('settings')->default('{}');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_sessions');
    }
};
