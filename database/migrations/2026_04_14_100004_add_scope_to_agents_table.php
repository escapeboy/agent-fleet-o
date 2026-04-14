<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('scope')->default('team')->after('status');
            $table->foreignUuid('owner_user_id')->nullable()->after('scope')
                ->constrained('users')->nullOnDelete();
            $table->index(['team_id', 'scope'], 'idx_agents_team_scope');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex('idx_agents_team_scope');
            $table->dropForeign(['owner_user_id']);
            $table->dropColumn(['scope', 'owner_user_id']);
        });
    }
};
