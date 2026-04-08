<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_tool', function (Blueprint $table) {
            $table->string('approval_mode', 10)->default('auto');
            $table->unsignedSmallInteger('approval_timeout_minutes')->default(30);
            $table->string('approval_timeout_action', 10)->default('deny');
        });
    }

    public function down(): void
    {
        Schema::table('agent_tool', function (Blueprint $table) {
            $table->dropColumn(['approval_mode', 'approval_timeout_minutes', 'approval_timeout_action']);
        });
    }
};
