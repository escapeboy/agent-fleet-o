<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tables with a direct team_id column — RLS is applied with RESTRICTIVE policies.
     * Child tables without team_id (skill_versions, playbook_steps, workflow_nodes, etc.)
     * are protected indirectly through their parent model's RLS.
     *
     * semantic_cache_entries is intentionally cross-tenant (shared LLM cache).
     */
    private array $tenantTables = [
        // Core domain (added by add_team_id_to_domain_tables migration)
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
        // Skill domain
        'skills',
        'skill_executions',
        // Agent domain
        'agent_executions',
        // Marketplace
        'marketplace_listings',
        'marketplace_installations',
        'marketplace_reviews',
        'marketplace_usage_records',
        // Crew domain
        'crews',
        'crew_executions',
        // Project domain
        'projects',
        // Workflow domain
        'workflows',
        // Infrastructure
        'memories',
        'credentials',
        'tools',
        'entities',
        // Communication
        'outbound_connector_configs',
        'webhook_endpoints',
        // Platform features
        'user_notifications',
        'worktree_executions',
        'assistant_conversations',
        'evolution_proposals',
        'test_suites',
        // Team-scoped shared
        'team_provider_credentials',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $currentUser = DB::selectOne('SELECT current_user AS u')->u;

        // 1. Create a non-superuser role that will be used for agent/job queries.
        //    NOINHERIT prevents it from automatically inheriting privileges from granted roles,
        //    which forces explicit SET ROLE to activate the limited privilege set.
        DB::statement('
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = \'agent_fleet_rls\') THEN
                    CREATE ROLE agent_fleet_rls
                        NOSUPERUSER
                        NOCREATEDB
                        NOCREATEROLE
                        NOINHERIT
                        NOLOGIN;
                END IF;
            END
            $$;
        ');

        // 2. Grant table permissions to the RLS role.
        //    GRANT USAGE on schema lets the role see the schema namespace.
        DB::statement('GRANT USAGE ON SCHEMA public TO agent_fleet_rls');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO agent_fleet_rls');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO agent_fleet_rls');

        // 3. Grant the RLS role to the current DB user so SET ROLE is allowed.
        DB::statement("GRANT agent_fleet_rls TO \"{$currentUser}\"");

        // 4. Create a LEAKPROOF STABLE function to read the current tenant context.
        //    LEAKPROOF: query planner can safely push conditions using this function below views/joins.
        //    STABLE:    result is constant within a single statement (ok for GUC reads).
        //    PARALLEL SAFE: allows parallel query plans.
        //    The 'true' second arg to current_setting prevents an error when the variable is unset.
        //    NULLIF prevents a cast error when the value is an empty string (unset state).
        DB::statement("
            CREATE OR REPLACE FUNCTION current_team_id()
            RETURNS uuid
            LANGUAGE sql
            STABLE
            LEAKPROOF
            PARALLEL SAFE
            AS \$func\$
                SELECT NULLIF(current_setting('app.current_team_id', TRUE), '')::uuid
            \$func\$;
        ");

        // 5. Enable RLS and create PERMISSIVE policies on all tenant tables.
        //    PERMISSIVE is the correct choice for tenant isolation: a row is accessible
        //    if it passes this policy (OR logic across permissive policies).
        //    RESTRICTIVE alone would deny all rows because PostgreSQL requires at least
        //    one PERMISSIVE policy to allow access — "only restrictive policies = deny all".
        //    FOR ALL covers SELECT, INSERT, UPDATE, DELETE in one policy.
        //    WITH CHECK ensures INSERT/UPDATE cannot write rows for a different team.
        foreach ($this->tenantTables as $table) {
            // Skip if the table doesn't exist (community vs cloud edition differences)
            $exists = DB::selectOne(
                "SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name=?",
                [$table],
            );
            if (! $exists) {
                continue;
            }

            DB::statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE \"{$table}\" FORCE ROW LEVEL SECURITY");

            // Drop existing policy if present (idempotent re-run)
            DB::statement("DROP POLICY IF EXISTS team_isolation ON \"{$table}\"");

            DB::statement("
                CREATE POLICY team_isolation ON \"{$table}\"
                AS PERMISSIVE
                FOR ALL
                USING (team_id = current_team_id())
                WITH CHECK (team_id = current_team_id())
            ");
        }

        // 6. semantic_cache_entries is intentionally cross-tenant (shared LLM response cache).
        //    Apply a permissive USING(true) policy so the non-superuser role can still read/write it.
        $cacheTableExists = DB::selectOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='semantic_cache_entries'",
        );
        if ($cacheTableExists) {
            DB::statement('ALTER TABLE "semantic_cache_entries" ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE "semantic_cache_entries" FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS allow_all ON "semantic_cache_entries"');
            DB::statement('
                CREATE POLICY allow_all ON "semantic_cache_entries"
                AS PERMISSIVE
                FOR ALL
                USING (true)
                WITH CHECK (true)
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Remove policies and disable RLS on tenant tables
        foreach ($this->tenantTables as $table) {
            $exists = DB::selectOne(
                "SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name=?",
                [$table],
            );
            if (! $exists) {
                continue;
            }

            DB::statement("DROP POLICY IF EXISTS team_isolation ON \"{$table}\"");
            DB::statement("ALTER TABLE \"{$table}\" DISABLE ROW LEVEL SECURITY");
        }

        // Revert semantic_cache_entries
        $cacheTableExists = DB::selectOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='semantic_cache_entries'",
        );
        if ($cacheTableExists) {
            DB::statement('DROP POLICY IF EXISTS allow_all ON "semantic_cache_entries"');
            DB::statement('ALTER TABLE "semantic_cache_entries" DISABLE ROW LEVEL SECURITY');
        }

        // Drop helper function
        DB::statement('DROP FUNCTION IF EXISTS current_team_id()');

        // Revoke role from current user and drop the role
        $currentUser = DB::selectOne('SELECT current_user AS u')->u;
        DB::statement("REVOKE agent_fleet_rls FROM \"{$currentUser}\"");
        DB::statement('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM agent_fleet_rls');
        DB::statement('REVOKE USAGE ON SCHEMA public FROM agent_fleet_rls');
        DB::statement("
            DO \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'agent_fleet_rls') THEN
                    DROP ROLE agent_fleet_rls;
                END IF;
            END
            \$\$;
        ");
    }
};
