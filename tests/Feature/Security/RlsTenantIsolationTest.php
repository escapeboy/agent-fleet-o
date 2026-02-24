<?php

namespace Tests\Feature\Security;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies that PostgreSQL Row Level Security correctly isolates tenant data.
 *
 * These tests require PostgreSQL — they are skipped automatically when running
 * against SQLite (the default test driver in CI / phpunit.xml).
 *
 * To run these locally against a real PostgreSQL instance:
 *   DB_CONNECTION=pgsql DB_DATABASE=agent_fleet_test php artisan test --filter=RlsTenantIsolation
 *
 * Prerequisites: the RLS migration must have run on the test database.
 */
class RlsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Team $teamA;

    private Team $teamB;

    private Agent $agentA;

    private Agent $agentB;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('RLS tests require PostgreSQL — skipping on '.DB::getDriverName());
        }

        // Verify the RLS role exists (migration has been run)
        $roleExists = DB::selectOne("SELECT 1 FROM pg_roles WHERE rolname = 'agent_fleet_rls'");
        if (! $roleExists) {
            $this->markTestSkipped('RLS migration has not been run — skipping RLS isolation tests');
        }

        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        $this->teamA = Team::create([
            'name' => 'Team A',
            'slug' => 'team-a',
            'owner_id' => $ownerA->id,
            'settings' => [],
        ]);

        $this->teamB = Team::create([
            'name' => 'Team B',
            'slug' => 'team-b',
            'owner_id' => $ownerB->id,
            'settings' => [],
        ]);

        // Create one agent per team using Eloquent (bypasses RLS since we haven't switched role yet)
        $this->agentA = Agent::withoutGlobalScopes()->create([
            'team_id' => $this->teamA->id,
            'name' => 'Agent Alpha',
            'slug' => 'agent-alpha',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'status' => 'active',
        ]);

        $this->agentB = Agent::withoutGlobalScopes()->create([
            'team_id' => $this->teamB->id,
            'name' => 'Agent Beta',
            'slug' => 'agent-beta',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        // Always reset role after tests to avoid polluting subsequent tests
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('RESET ROLE');
            DB::statement("SELECT set_config('app.current_team_id', '', false)");
        }

        parent::tearDown();
    }

    /** @test */
    public function rls_allows_team_a_to_see_only_its_own_agents(): void
    {
        $this->withRlsContext($this->teamA->id, function (): void {
            $agents = Agent::withoutGlobalScopes()->get();

            $this->assertCount(1, $agents, 'Team A should see exactly 1 agent');
            $this->assertEquals($this->agentA->id, $agents->first()->id);
            $this->assertEquals($this->teamA->id, $agents->first()->team_id);
        });
    }

    /** @test */
    public function rls_allows_team_b_to_see_only_its_own_agents(): void
    {
        $this->withRlsContext($this->teamB->id, function (): void {
            $agents = Agent::withoutGlobalScopes()->get();

            $this->assertCount(1, $agents, 'Team B should see exactly 1 agent');
            $this->assertEquals($this->agentB->id, $agents->first()->id);
            $this->assertEquals($this->teamB->id, $agents->first()->team_id);
        });
    }

    /** @test */
    public function rls_blocks_direct_access_to_another_teams_agent_by_id(): void
    {
        $this->withRlsContext($this->teamA->id, function (): void {
            // Team A tries to find Team B's agent directly by ID
            $agent = Agent::withoutGlobalScopes()->find($this->agentB->id);

            $this->assertNull($agent, 'Team A should not be able to fetch Team B\'s agent');
        });
    }

    /** @test */
    public function rls_blocks_all_access_when_no_team_context_is_set(): void
    {
        $this->withRlsContext('', function (): void {
            $agents = Agent::withoutGlobalScopes()->get();

            $this->assertCount(0, $agents, 'No team context should block all agent rows');
        });
    }

    /** @test */
    public function rls_prevents_insert_for_wrong_team(): void
    {
        $this->withRlsContext($this->teamA->id, function (): void {
            $this->expectException(QueryException::class);

            // Attempt to insert a row with Team B's team_id while context is Team A
            Agent::withoutGlobalScopes()->create([
                'team_id' => $this->teamB->id,
                'name' => 'Injected Agent',
                'slug' => 'injected-agent',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'status' => 'active',
            ]);
        });
    }

    /** @test */
    public function rls_context_resets_between_calls(): void
    {
        // First context: Team A
        $this->withRlsContext($this->teamA->id, function (): void {
            $count = Agent::withoutGlobalScopes()->count();
            $this->assertEquals(1, $count, 'Should see 1 agent for Team A');
        });

        // After the context resets, switch to Team B
        $this->withRlsContext($this->teamB->id, function (): void {
            $count = Agent::withoutGlobalScopes()->count();
            $this->assertEquals(1, $count, 'Should see 1 agent for Team B after context switch');
        });
    }

    /** @test */
    public function semantic_cache_entries_are_accessible_across_teams(): void
    {
        // semantic_cache_entries has a PERMISSIVE allow_all policy
        $tableExists = DB::selectOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='semantic_cache_entries'",
        );

        if (! $tableExists) {
            $this->markTestSkipped('semantic_cache_entries table not present');
        }

        $this->withRlsContext($this->teamA->id, function (): void {
            // Insert a row — should succeed without team_id (cross-tenant cache)
            DB::table('semantic_cache_entries')->insert([
                'id' => Str::uuid7()->toString(),
                'prompt_hash' => hash('sha256', 'test-prompt'),
                'prompt_text' => 'test-prompt',
                'response_text' => 'test-response',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-5',
                'expires_at' => now()->addDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $count = DB::table('semantic_cache_entries')->count();
            $this->assertGreaterThan(0, $count, 'Cross-tenant cache table should be readable');
        });
    }

    /**
     * Execute a callback inside a transaction with the agent_fleet_rls role
     * and the given team_id as the RLS context.
     * The transaction is rolled back after the callback, so DB state is clean.
     */
    private function withRlsContext(string $teamId, callable $callback): void
    {
        DB::transaction(function () use ($teamId, $callback): void {
            DB::statement("SELECT set_config('app.current_team_id', ?, true)", [$teamId]);
            DB::statement('SET LOCAL ROLE agent_fleet_rls');

            $callback();

            // Transaction rolls back automatically (RefreshDatabase wraps everything in a txn,
            // and our nested SET LOCAL only lives until this inner transaction boundary).
        });
    }
}
