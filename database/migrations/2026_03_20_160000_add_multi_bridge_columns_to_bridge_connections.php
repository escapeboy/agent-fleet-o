<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bridge_connections', function (Blueprint $table) {
            $table->string('label', 100)->nullable()->after('session_id');
            $table->unsignedSmallInteger('priority')->default(0)->after('label');
        });

        Schema::table('bridge_connections', function (Blueprint $table) {
            $table->index(['team_id', 'status', 'priority'], 'bc_team_status_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bridge_connections', function (Blueprint $table) {
            $table->dropIndex('bc_team_status_priority_idx');
            $table->dropColumn(['label', 'priority']);
        });
    }
};
