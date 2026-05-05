<?php

use App\Domain\Crew\Models\CrewExecution;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The original add_crew_execution_id_to_websites_table migration sits at
        // 2026_04_04, BEFORE create_websites_table at 2026_04_06. On a fresh DB
        // the column-add runs first, sees no table, and skips — leaving prod
        // (which migrated incrementally) and test (which migrates fresh) with
        // divergent schemas. This re-runs the column-add at a timestamp that is
        // guaranteed to be after the table exists.
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
