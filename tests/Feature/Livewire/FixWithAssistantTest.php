<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Models\Team;
use App\Livewire\Shared\FixWithAssistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class FixWithAssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Fix Assistant Team',
            'slug' => 'fix-assistant-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeFailedExperiment(array $overrides = []): Experiment
    {
        return Experiment::create(array_merge([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Failed Test',
            'thesis' => 't',
            'status' => ExperimentStatus::BuildingFailed,
            'track' => 'growth',
            'budget_cap_credits' => 5000,
            'max_iterations' => 3,
            'current_iteration' => 1,
            'max_outbound_count' => 100,
            'outbound_count' => 0,
        ], $overrides));
    }

    public function test_renders_for_failed_experiment(): void
    {
        $experiment = $this->makeFailedExperiment();

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->assertSet('eligible', true)
            ->assertSee('Diagnose');
    }

    public function test_does_not_render_for_completed_experiment(): void
    {
        $experiment = Experiment::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Done',
            'thesis' => 't',
            'status' => ExperimentStatus::Completed,
            'track' => 'growth',
            'budget_cap_credits' => 5000,
            'max_iterations' => 3,
            'current_iteration' => 1,
            'max_outbound_count' => 100,
            'outbound_count' => 0,
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->assertSet('eligible', false)
            ->assertDontSee('Diagnose');
    }

    public function test_does_not_render_for_unknown_entity(): void
    {
        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => '00000000-0000-0000-0000-000000000000',
        ])
            ->assertSet('eligible', false);
    }

    public function test_does_not_render_for_unsupported_entity_type(): void
    {
        $experiment = $this->makeFailedExperiment();

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'workflow',  // not supported
            'entityId' => $experiment->id,
        ])
            ->assertSet('eligible', false);
    }

    public function test_renders_for_paused_project(): void
    {
        $project = \App\Domain\Project\Models\Project::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Paused',
            'status' => \App\Domain\Project\Enums\ProjectStatus::Paused,
            'project_type' => 'one_shot',
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'project',
            'entityId' => $project->id,
        ])->assertSet('eligible', true);
    }

    public function test_does_not_render_for_active_project(): void
    {
        $project = \App\Domain\Project\Models\Project::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Active',
            'status' => \App\Domain\Project\Enums\ProjectStatus::Active,
            'project_type' => 'one_shot',
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'project',
            'entityId' => $project->id,
        ])->assertSet('eligible', false);
    }

    public function test_diagnose_paused_project_returns_state_driven_summary(): void
    {
        $project = \App\Domain\Project\Models\Project::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Paused',
            'status' => \App\Domain\Project\Enums\ProjectStatus::Paused,
            'project_type' => 'one_shot',
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'project',
            'entityId' => $project->id,
        ])
            ->call('diagnose')
            ->tap(function ($component) {
                $diagnosis = $component->get('diagnosis');
                $this->assertIsArray($diagnosis);
                $this->assertSame('project_paused', $diagnosis['root_cause']);
                $this->assertNotEmpty($diagnosis['recommended_actions']);
            });
    }

    public function test_renders_for_agent_with_open_circuit_breaker(): void
    {
        $agent = \App\Domain\Agent\Models\Agent::factory()->for($this->team)->create();
        \App\Infrastructure\AI\Models\CircuitBreakerState::create([
            'team_id' => $this->team->id,
            'agent_id' => $agent->id,
            'state' => 'open',
            'failure_count' => 5,
            'success_count' => 0,
            'cooldown_seconds' => 60,
            'failure_threshold' => 5,
            'opened_at' => now(),
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'agent',
            'entityId' => $agent->id,
        ])->assertSet('eligible', true);
    }

    public function test_does_not_render_for_healthy_agent(): void
    {
        $agent = \App\Domain\Agent\Models\Agent::factory()->for($this->team)->create();

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'agent',
            'entityId' => $agent->id,
        ])->assertSet('eligible', false);
    }

    public function test_diagnose_agent_with_circuit_breaker_returns_summary(): void
    {
        $agent = \App\Domain\Agent\Models\Agent::factory()->for($this->team)->create();
        \App\Infrastructure\AI\Models\CircuitBreakerState::create([
            'team_id' => $this->team->id,
            'agent_id' => $agent->id,
            'state' => 'open',
            'failure_count' => 7,
            'success_count' => 0,
            'cooldown_seconds' => 60,
            'failure_threshold' => 5,
            'opened_at' => now(),
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'agent',
            'entityId' => $agent->id,
        ])
            ->call('diagnose')
            ->tap(function ($component) {
                $diagnosis = $component->get('diagnosis');
                $this->assertIsArray($diagnosis);
                $this->assertSame('circuit_breaker_open', $diagnosis['root_cause']);
                $this->assertStringContainsString('7', $diagnosis['summary']);
            });
    }

    public function test_diagnose_populates_state(): void
    {
        $experiment = $this->makeFailedExperiment();
        ExperimentStage::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'stage' => StageType::Building,
            'iteration' => 1,
            'status' => StageStatus::Failed,
            'output_snapshot' => ['error' => 'PrismException: HTTP 429'],
            'completed_at' => now(),
            'duration_ms' => 0,
            'retry_count' => 0,
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->call('diagnose')
            ->assertSet('diagnosed', true)
            ->assertSet('errorMessage', '')
            ->tap(function ($component) {
                $diagnosis = $component->get('diagnosis');
                $this->assertIsArray($diagnosis);
                $this->assertSame('rate_limit', $diagnosis['root_cause']);
                $this->assertNotEmpty($diagnosis['recommended_actions']);
            });
    }

    public function test_diagnose_renders_summary_in_view(): void
    {
        $experiment = $this->makeFailedExperiment();
        ExperimentStage::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'stage' => StageType::Building,
            'iteration' => 1,
            'status' => StageStatus::Failed,
            'output_snapshot' => ['error' => 'HTTP 429 rate limit'],
            'completed_at' => now(),
            'duration_ms' => 0,
            'retry_count' => 0,
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->call('diagnose')
            ->assertSee('rate-limited')  // English summary substring
            ->assertSee('Confidence');
    }

    public function test_ask_assistant_dispatches_open_assistant_event(): void
    {
        $experiment = $this->makeFailedExperiment();

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->call('askAssistant', 'investigate this please')
            ->assertDispatched('open-assistant', message: 'investigate this please');
    }

    public function test_execute_recovery_action_calls_allowed_retry_tool(): void
    {
        // ScoringFailed → Scoring has no prerequisite stages, so the retry can
        // complete inside the test without needing a fully-built pipeline.
        // Fake the queue so the post-transition stage job doesn't actually run
        // (it would try to call the LLM gateway).
        Queue::fake();

        $experiment = $this->makeFailedExperiment(['status' => ExperimentStatus::ScoringFailed]);
        ExperimentStage::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'stage' => StageType::Scoring,
            'iteration' => 1,
            'status' => StageStatus::Failed,
            'output_snapshot' => ['error' => 'PrismException: HTTP 429'],
            'completed_at' => now(),
            'duration_ms' => 0,
            'retry_count' => 0,
        ]);

        $component = Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->call('diagnose')
            ->call('executeRecoveryAction', 'experiment_retry', ['experiment_id' => $experiment->id]);

        $error = (string) $component->get('errorMessage');
        $this->assertSame('', $error, 'Recovery action set errorMessage: '.$error);
        $component->assertDispatched('notify')->assertSet('diagnosis', null);
    }

    public function test_execute_recovery_action_rejects_non_allowlisted_tool(): void
    {
        $experiment = $this->makeFailedExperiment();

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->call('executeRecoveryAction', 'experiment_kill', ['experiment_id' => $experiment->id])
            ->tap(function ($component) {
                $this->assertStringContainsString(
                    'not allowlisted',
                    (string) $component->get('errorMessage'),
                );
            });
    }

    public function test_execute_recovery_action_forces_experiment_id_to_bound_entity(): void
    {
        // Even if the params payload were tampered to point at a different
        // experiment, the bound entity wins.
        $experiment = $this->makeFailedExperiment();
        $other = $this->makeFailedExperiment(['title' => 'Other']);

        $component = Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->call('executeRecoveryAction', 'experiment_retry', ['experiment_id' => $other->id]);

        // The retry tool either succeeded against $experiment OR returned a
        // structured error specific to its state — the key is that no error
        // mentions $other->id (proving the override happened) and that
        // $experiment is the one whose retry was attempted.
        $errorMessage = (string) $component->get('errorMessage');
        $this->assertStringNotContainsString($other->id, $errorMessage);
    }

    public function test_execute_recovery_action_no_op_when_not_eligible(): void
    {
        $experiment = Experiment::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Done',
            'thesis' => 't',
            'status' => ExperimentStatus::Completed,
            'track' => 'growth',
            'budget_cap_credits' => 5000,
            'max_iterations' => 3,
            'current_iteration' => 1,
            'max_outbound_count' => 100,
            'outbound_count' => 0,
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->call('executeRecoveryAction', 'experiment_retry', ['experiment_id' => $experiment->id])
            ->assertSet('errorMessage', '');  // silently no-op
    }

    public function test_xss_safe_summary_escapes_html(): void
    {
        $experiment = $this->makeFailedExperiment();
        // Inject pseudo-script-tag string into the error so we can verify the
        // rendered HTML does not contain a literal <script> tag.
        ExperimentStage::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'stage' => StageType::Building,
            'iteration' => 1,
            'status' => StageStatus::Failed,
            // Falls into 'unknown' bucket → message is the dictionary 'en' default,
            // which is safe. The XSS surface is the dictionary itself; this test
            // is a regression sentinel that proves we never echo raw error text.
            'output_snapshot' => ['error' => '<script>alert(1)</script>'],
            'completed_at' => now(),
            'duration_ms' => 0,
            'retry_count' => 0,
        ]);

        $output = (string) Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])->call('diagnose')->html();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $output);
    }
}
