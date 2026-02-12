<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_executions', function (Blueprint $table) {
            $table->jsonb('tools_used')->default('[]')->after('skills_executed');
            $table->integer('tool_calls_count')->default(0)->after('tools_used');
            $table->integer('llm_steps_count')->default(0)->after('tool_calls_count');
        });
    }

    public function down(): void
    {
        Schema::table('agent_executions', function (Blueprint $table) {
            $table->dropColumn(['tools_used', 'tool_calls_count', 'llm_steps_count']);
        });
    }
};
