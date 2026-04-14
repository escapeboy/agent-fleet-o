<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_request_logs', function (Blueprint $table) {
            $table->foreignUuid('user_id')->nullable()->after('team_id')
                ->constrained('users')->nullOnDelete();
            $table->index(['team_id', 'user_id'], 'idx_llm_request_logs_team_user');
        });
    }

    public function down(): void
    {
        Schema::table('llm_request_logs', function (Blueprint $table) {
            $table->dropIndex('idx_llm_request_logs_team_user');
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
