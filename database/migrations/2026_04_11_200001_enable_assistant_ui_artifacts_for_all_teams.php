<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gap 2 rollout: flip assistant UI artifacts ON for every existing team and
 * make it the default for new teams.
 *
 * This migration does NOT flip the global kill switch (GlobalSetting
 * 'assistant.ui_artifacts_enabled') — that remains a runtime operator
 * control. Flip it manually via tinker:
 *
 *   app(\App\Domain\Assistant\Services\AssistantArtifactsFeatureFlag::class)
 *       ->setGlobalEnabled(true);
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Flip every existing team's flag to true.
        DB::table('teams')->update(['assistant_ui_artifacts_allowed' => true]);

        // 2. Change the column default so new teams get it automatically.
        //    PostgreSQL supports ALTER COLUMN ... SET DEFAULT; SQLite is
        //    awkward here — we fall back to a column recreate via Blueprint.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE teams ALTER COLUMN assistant_ui_artifacts_allowed SET DEFAULT true');
        } else {
            Schema::table('teams', function (Blueprint $table) {
                $table->boolean('assistant_ui_artifacts_allowed')->default(true)->change();
            });
        }
    }

    public function down(): void
    {
        // Reverting flips the default back to false and clears all existing flags.
        DB::table('teams')->update(['assistant_ui_artifacts_allowed' => false]);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE teams ALTER COLUMN assistant_ui_artifacts_allowed SET DEFAULT false');
        } else {
            Schema::table('teams', function (Blueprint $table) {
                $table->boolean('assistant_ui_artifacts_allowed')->default(false)->change();
            });
        }
    }
};
