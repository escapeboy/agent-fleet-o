<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\PageHelp\AgentDetailHelpResolver;
use App\Domain\Shared\Services\PageHelpResolver;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageHelpResolverTest extends TestCase
{
    use RefreshDatabase;

    private PageHelpResolver $resolver;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PageHelpResolver;

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Page Help Test',
            'slug' => 'page-help-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($this->user);
    }

    public function test_returns_static_help_when_no_dynamic_resolver(): void
    {
        $result = $this->resolver->resolve('dashboard');

        $this->assertNotNull($result);
        $this->assertSame('Dashboard', $result['title']);
    }

    public function test_returns_null_for_unknown_route(): void
    {
        $this->assertNull($this->resolver->resolve('completely.unknown.route'));
    }

    public function test_dynamic_resolver_overrides_when_circuit_breaker_open(): void
    {
        $agent = Agent::factory()->for($this->team)->create();
        CircuitBreakerState::create([
            'team_id' => $this->team->id,
            'agent_id' => $agent->id,
            'state' => 'open',
            'failure_count' => 7,
            'success_count' => 0,
            'cooldown_seconds' => 60,
            'failure_threshold' => 5,
            'opened_at' => now(),
        ]);

        $result = $this->resolver->resolve('agents.show', ['agent' => $agent->fresh()]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('circuit breaker', $result['description']);
        $this->assertStringContainsString('7', $result['description']);
        // Dynamic override replaced steps and tips
        $this->assertNotEmpty($result['steps']);
    }

    public function test_dynamic_resolver_returns_null_falls_back_to_static(): void
    {
        // Healthy agent — resolver returns null, static help wins
        $agent = Agent::factory()->for($this->team)->create();

        $result = $this->resolver->resolve('agents.show', ['agent' => $agent]);

        $staticAll = config('page-help', []);
        $this->assertSame($staticAll['agents.show'] ?? null, $result);
    }

    public function test_dynamic_resolver_handles_failed_experiment(): void
    {
        $experiment = Experiment::factory()
            ->for($this->team)
            ->for($this->user)
            ->create(['status' => ExperimentStatus::BuildingFailed]);

        $result = $this->resolver->resolve('experiments.show', ['experiment' => $experiment]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('Diagnose', $result['description']);
    }

    public function test_dynamic_resolver_handles_paused_experiment(): void
    {
        $experiment = Experiment::factory()
            ->for($this->team)
            ->for($this->user)
            ->create(['status' => ExperimentStatus::Paused]);

        $result = $this->resolver->resolve('experiments.show', ['experiment' => $experiment]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('paused', strtolower($result['description']));
    }

    public function test_dynamic_resolver_handles_paused_project(): void
    {
        $project = Project::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Paused Project',
            'status' => ProjectStatus::Paused,
            'project_type' => 'one_shot',
        ]);

        $result = $this->resolver->resolve('projects.show', ['project' => $project]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('paused', strtolower($result['description']));
    }

    public function test_dynamic_resolver_failure_does_not_break_page(): void
    {
        // Configure a resolver that throws
        config()->set('page-help-dynamic.agents.show', ThrowingHelpResolver::class);

        $agent = Agent::factory()->for($this->team)->create();
        $result = $this->resolver->resolve('agents.show', ['agent' => $agent]);

        // Static help still returned despite resolver throwing
        $this->assertNotNull($result);
    }

    public function test_dynamic_resolver_with_wrong_param_type_returns_null(): void
    {
        // Pass a string instead of an Agent model — resolver returns null,
        // page-help falls back to static.
        $result = $this->resolver->resolve('agents.show', ['agent' => 'not-an-agent-model']);

        $staticAll = config('page-help', []);
        $this->assertSame($staticAll['agents.show'] ?? null, $result);
    }

    public function test_resolver_class_string_form_is_supported(): void
    {
        // Direct flat-key lookup since dotted route names break dot-notation:
        $all = config('page-help-dynamic');
        $this->assertIsArray($all);
        $this->assertSame(
            AgentDetailHelpResolver::class,
            $all['agents.show'] ?? null,
        );
    }
}

class ThrowingHelpResolver
{
    /** @param  array<string, mixed>  $params */
    public function __invoke(array $params): array
    {
        throw new \RuntimeException('boom');
    }
}
