<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            if (! Schema::hasColumn('memories', 'task_type')) {
                $table->string('task_type', 64)->nullable();
                $table->index(['team_id', 'task_type', 'tier'], 'memories_team_task_type_tier_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            if (Schema::hasColumn('memories', 'task_type')) {
                $table->dropIndex('memories_team_task_type_tier_idx');
                $table->dropColumn('task_type');
            }
        });
    }
};
