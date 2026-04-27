<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_proposals', function (Blueprint $table) {
            $table->timestamp('executed_at')->nullable()->after('decided_at');
            $table->jsonb('execution_result')->nullable()->after('executed_at');
            $table->text('execution_error')->nullable()->after('execution_result');
        });
    }

    public function down(): void
    {
        Schema::table('action_proposals', function (Blueprint $table) {
            $table->dropColumn(['executed_at', 'execution_result', 'execution_error']);
        });
    }
};
