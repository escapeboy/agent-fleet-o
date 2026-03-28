<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->boolean('mcp_exposed')->default(false)->after('budget_cap_credits');
            $table->string('mcp_tool_name')->nullable()->unique()->after('mcp_exposed');
            $table->string('mcp_execution_mode', 20)->default('async')->after('mcp_tool_name');
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropUnique(['mcp_tool_name']);
            $table->dropColumn(['mcp_exposed', 'mcp_tool_name', 'mcp_execution_mode']);
        });
    }
};
