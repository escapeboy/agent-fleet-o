<?php

use App\Domain\Crew\Models\CrewExecution;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent guards: this migration's timestamp (04_04) is before the
        // canonical websites table creation (04_06_000001), which makes the
        // fresh migration order fragile. Skip cleanly if the prerequisite
        // table/column is not yet/already in the expected state.
        if (! Schema::hasTable('websites')) {
            return;
        }

        if (Schema::hasColumn('websites', 'crew_execution_id')) {
            return;
        }

        Schema::table('websites', function (Blueprint $table) {
            $table->foreignUuid('crew_execution_id')->nullable()->after('settings')
                ->constrained('crew_executions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('websites') || ! Schema::hasColumn('websites', 'crew_execution_id')) {
            return;
        }

        Schema::table('websites', function (Blueprint $table) {
            $table->dropForeignIdFor(CrewExecution::class, 'crew_execution_id');
            $table->dropColumn('crew_execution_id');
        });
    }
};
