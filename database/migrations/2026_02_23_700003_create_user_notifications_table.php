<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('type');            // 'approval_needed', 'experiment_stuck', 'budget_alert', etc.
            $table->string('title');
            $table->text('body');
            $table->string('action_url')->nullable();  // Link to relevant page
            $table->jsonb('data')->nullable();          // Type-specific extra data
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'team_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
