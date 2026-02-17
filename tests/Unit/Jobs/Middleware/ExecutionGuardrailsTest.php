<?php

namespace Tests\Unit\Jobs\Middleware;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Models\Team;
use App\Jobs\Middleware\EnforceConcurrencyLimit;
use App\Jobs\Middleware\EnforceExecutionDepth;
use App\Jobs\Middleware\EnforceExecutionTtl;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutionGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);

        $this->agent = Agent::create([
            'team_id' => $this->team->id,
            'name' => 'Test Agent',
            'slug' => 'test-agent',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'status' => 'active',
        ]);
    }

    public function test_ttl_config_defaults(): void
    {
        $this->assertEquals(120, config('experiments.default_ttl_minutes'));
        $this->assertEquals(50, config('experiments.default_max_depth'));
        $this->assertEquals(5, config('experiments.default_max_concurrent'));
    }

    public function test_ttl_middleware_can_be_instantiated(): void
    {
        $middleware = new EnforceExecutionTtl;
        $this->assertInstanceOf(EnforceExecutionTtl::class, $middleware);
    }

    public function test_depth_middleware_can_be_instantiated(): void
    {
        $middleware = new EnforceExecutionDepth;
        $this->assertInstanceOf(EnforceExecutionDepth::class, $middleware);
    }

    public function test_concurrency_middleware_can_be_instantiated(): void
    {
        $middleware = new EnforceConcurrencyLimit;
        $this->assertInstanceOf(EnforceConcurrencyLimit::class, $middleware);
    }
}
