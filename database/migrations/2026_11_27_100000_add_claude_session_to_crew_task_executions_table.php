<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_task_executions', function (Blueprint $table) {
            $table->string('claude_session_id', 100)->nullable();
            $table->string('claude_session_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crew_task_executions', function (Blueprint $table) {
            $table->dropColumn(['claude_session_id', 'claude_session_hash']);
        });
    }
};
