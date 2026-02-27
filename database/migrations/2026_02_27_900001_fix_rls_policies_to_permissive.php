<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: Replace RESTRICTIVE RLS policies with PERMISSIVE ones.
 *
 * The original enable_rls_on_tenant_tables migration incorrectly used
 * AS RESTRICTIVE for all tenant isolation policies. PostgreSQL requires
 * at least one PERMISSIVE policy to allow row access — with only RESTRICTIVE
 * policies, all rows are denied for non-superuser roles regardless of whether
 * the policy condition evaluates to TRUE.
 *
 * This caused SQLSTATE[42501] "new row violates row-level security policy"
 * on any INSERT/UPDATE executed via the agent_fleet_rls role (which the
 * SetPostgresRlsContext middleware activates for all authenticated web requests).
 */
return new class extends Migration
{
    private array $tenantTables = [
        'agents',
        'experiments',
        'experiment_stages',
        'experiment_state_transitions',
        'experiment_tasks',
        'signals',
        'artifacts',
        'artifact_versions',
        'ai_runs',
        'outbound_proposals',
        'outbound_actions',
        'approval_requests',
        'metrics',
        'metric_aggregations',
        'credit_ledgers',
        'audit_entries',
        'connectors',
        'blacklists',
        'llm_request_logs',
        'circuit_breaker_states',
        'skills',
        'skill_executions',
        'agent_executions',
        'marketplace_listings',
        'marketplace_installations',
        'marketplace_reviews',
        'marketplace_usage_records',
        'crews',
        'crew_executions',
        'projects',
        'workflows',
        'memories',
        'credentials',
        'tools',
        'entities',
        'outbound_connector_configs',
        'webhook_endpoints',
        'user_notifications',
        'worktree_executions',
        'assistant_conversations',
        'evolution_proposals',
        'test_suites',
        'team_provider_credentials',
        // Tables added after the original RLS migration
        'trigger_rules',
        'telegram_bots',
        'telegram_chat_bindings',
        'connector_bindings',
        'contact_identities',
        'contact_channels',
        'integrations',
        'webhook_routes',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Check if RLS infrastructure exists (migration may not have run yet on fresh installs)
        $rlsExists = DB::selectOne("SELECT 1 FROM pg_roles WHERE rolname = 'agent_fleet_rls'");
        if (! $rlsExists) {
            return;
        }

        foreach ($this->tenantTables as $table) {
            $exists = DB::selectOne(
                "SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name=?",
                [$table],
            );
            if (! $exists) {
                continue;
            }

            // Ensure RLS is enabled (covers tables added after the original migration)
            DB::statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE \"{$table}\" FORCE ROW LEVEL SECURITY");

            // Drop the old RESTRICTIVE policy and recreate as PERMISSIVE.
            // PERMISSIVE is correct for tenant isolation: at least one permissive policy
            // must pass for a row to be accessible. RESTRICTIVE alone = deny all.
            DB::statement("DROP POLICY IF EXISTS team_isolation ON \"{$table}\"");

            DB::statement("
                CREATE POLICY team_isolation ON \"{$table}\"
                AS PERMISSIVE
                FOR ALL
                USING (team_id = current_team_id())
                WITH CHECK (team_id = current_team_id())
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $rlsExists = DB::selectOne("SELECT 1 FROM pg_roles WHERE rolname = 'agent_fleet_rls'");
        if (! $rlsExists) {
            return;
        }

        // Revert to RESTRICTIVE (the original, broken state — kept for rollback symmetry)
        foreach ($this->tenantTables as $table) {
            $exists = DB::selectOne(
                "SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name=?",
                [$table],
            );
            if (! $exists) {
                continue;
            }

            DB::statement("DROP POLICY IF EXISTS team_isolation ON \"{$table}\"");

            DB::statement("
                CREATE POLICY team_isolation ON \"{$table}\"
                AS RESTRICTIVE
                FOR ALL
                USING (team_id = current_team_id())
                WITH CHECK (team_id = current_team_id())
            ");
        }
    }
};
