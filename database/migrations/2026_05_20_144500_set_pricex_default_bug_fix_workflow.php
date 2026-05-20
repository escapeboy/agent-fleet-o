<?php

use Database\Seeders\SentryAutoFixWorkflowSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Wires the PriceX Ltd. team to the reusable "Sentry Auto-Fix" workflow as
 * its default bug-fix workflow. The Sentry Watchdog (Phase 1) consults this
 * pointer via DelegateBugReportToAgentAction when no agent-level default is set.
 *
 * Idempotent: the seeder uses updateOrCreate (keyed on team_id + name), and
 * the teams update is a single-row SET that converges on re-run.
 */
return new class extends Migration
{
    private const TEAM_ID = SentryAutoFixWorkflowSeeder::TEAM_ID;

    private const WORKFLOW_NAME = SentryAutoFixWorkflowSeeder::WORKFLOW_NAME;

    public function up(): void
    {
        Artisan::call('db:seed', [
            '--class' => SentryAutoFixWorkflowSeeder::class,
            '--force' => true,
        ]);

        $workflow = DB::table('workflows')
            ->where('team_id', self::TEAM_ID)
            ->where('name', self::WORKFLOW_NAME)
            ->first();

        if ($workflow) {
            DB::table('teams')
                ->where('id', self::TEAM_ID)
                ->update(['default_bug_fix_workflow_id' => $workflow->id]);
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive — leave the workflow alive so any
        // referencing experiments retain their wiring; an operator can remove
        // the row via the workflows UI once they confirm nothing depends on it.
    }
};
