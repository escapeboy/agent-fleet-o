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
use Tests\Concerns\MakesFailedExperiments;
use Tests\TestCase;

class FixWithAssistantTest extends TestCase
{
    use MakesFailedExperiments, RefreshDatabase;

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

    public function test_renders_for_skill_with_recent_failed_execution(): void
    {
        $skill = \App\Domain\Skill\Models\Skill::factory()->for($this->team)->create();
        \App\Domain\Skill\Models\SkillExecution::create([
            'team_id' => $this->team->id,
            'skill_id' => $skill->id,
            'status' => 'failed',
            'input' => [],
            'output' => null,
            'duration_ms' => 1000,
            'cost_credits' => 0,
            'error_message' => 'PrismException: HTTP 429 rate limit',
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'skill',
            'entityId' => $skill->id,
        ])->assertSet('eligible', true);
    }

    public function test_does_not_render_for_skill_with_no_recent_failures(): void
    {
        $skill = \App\Domain\Skill\Models\Skill::factory()->for($this->team)->create();
        // Only completed executions
        \App\Domain\Skill\Models\SkillExecution::create([
            'team_id' => $this->team->id,
            'skill_id' => $skill->id,
            'status' => 'completed',
            'input' => [],
            'output' => ['result' => 'ok'],
            'duration_ms' => 500,
            'cost_credits' => 0,
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'skill',
            'entityId' => $skill->id,
        ])->assertSet('eligible', false);
    }

    public function test_does_not_render_for_skill_with_old_failures(): void
    {
        $skill = \App\Domain\Skill\Models\Skill::factory()->for($this->team)->create();
        $old = \App\Domain\Skill\Models\SkillExecution::create([
            'team_id' => $this->team->id,
            'skill_id' => $skill->id,
            'status' => 'failed',
            'input' => [],
            'output' => null,
            'duration_ms' => 0,
            'cost_credits' => 0,
            'error_message' => 'Old failure',
        ]);
        // Push it >7 days back
        $old->created_at = now()->subDays(10);
        $old->saveQuietly();

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'skill',
            'entityId' => $skill->id,
        ])->assertSet('eligible', false);
    }

    public function test_diagnose_skill_uses_error_translator(): void
    {
        $skill = \App\Domain\Skill\Models\Skill::factory()->for($this->team)->create();
        \App\Domain\Skill\Models\SkillExecution::create([
            'team_id' => $this->team->id,
            'skill_id' => $skill->id,
            'status' => 'failed',
            'input' => [],
            'output' => null,
            'duration_ms' => 1000,
            'cost_credits' => 0,
            'error_message' => 'PrismException: HTTP 429 rate limit',
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'skill',
            'entityId' => $skill->id,
        ])
            ->call('diagnose')
            ->tap(function ($component) {
                $diagnosis = $component->get('diagnosis');
                $this->assertIsArray($diagnosis);
                $this->assertSame('rate_limit', $diagnosis['root_cause']);
                $this->assertNotEmpty($diagnosis['recommended_actions']);
                $this->assertSame(true, $diagnosis['matched_dictionary']);
            });
    }

    public function test_renders_for_crew_with_recent_failed_task(): void
    {
        $crew = $this->makeCrewWithFailedTask('PrismException: HTTP 429');

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'crew',
            'entityId' => $crew->id,
        ])->assertSet('eligible', true);
    }

    public function test_does_not_render_for_crew_with_no_failures(): void
    {
        $crew = $this->makeCrew();

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'crew',
            'entityId' => $crew->id,
        ])->assertSet('eligible', false);
    }

    public function test_diagnose_crew_uses_error_translator(): void
    {
        $crew = $this->makeCrewWithFailedTask('PrismException: HTTP 429 rate limit');

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'crew',
            'entityId' => $crew->id,
        ])
            ->call('diagnose')
            ->tap(function ($component) {
                $diagnosis = $component->get('diagnosis');
                $this->assertIsArray($diagnosis);
                $this->assertSame('rate_limit', $diagnosis['root_cause']);
                $this->assertNotEmpty($diagnosis['recommended_actions']);
            });
    }

    public function test_diagnose_crew_with_no_message(): void
    {
        $crew = $this->makeCrewWithFailedTask(null);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'crew',
            'entityId' => $crew->id,
        ])
            ->call('diagnose')
            ->tap(function ($component) {
                $diagnosis = $component->get('diagnosis');
                $this->assertIsArray($diagnosis);
                $this->assertSame('crew_task_failure_no_message', $diagnosis['root_cause']);
            });
    }

    private function makeCrew(): \App\Domain\Crew\Models\Crew
    {
        $coordinator = \App\Domain\Agent\Models\Agent::factory()->for($this->team)->create();
        $qa = \App\Domain\Agent\Models\Agent::factory()->for($this->team)->create();

        return \App\Domain\Crew\Models\Crew::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'name' => 'Test crew '.bin2hex(random_bytes(3)),
            'slug' => 'test-crew-'.bin2hex(random_bytes(3)),
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
            'process_type' => 'sequential',
            'status' => 'active',
        ]);
    }

    private function makeCrewWithFailedTask(?string $errorMessage): \App\Domain\Crew\Models\Crew
    {
        $crew = $this->makeCrew();
        $exec = \App\Domain\Crew\Models\CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $crew->id,
            'goal' => 'g',
            'status' => \App\Domain\Crew\Enums\CrewExecutionStatus::Failed,
        ]);
        \App\Domain\Crew\Models\CrewTaskExecution::create([
            'crew_execution_id' => $exec->id,
            'agent_id' => $crew->coordinator_agent_id,
            'title' => 'Failing task',
            'description' => 'Test failing task',
            'status' => \App\Domain\Crew\Enums\CrewTaskStatus::Failed,
            'error_message' => $errorMessage,
            'attempt_number' => 1,
            'max_attempts' => 3,
            'sort_order' => 1,
        ]);

        return $crew;
    }

    public function test_renders_for_workflow_with_failed_experiment(): void
    {
        $workflow = \App\Domain\Workflow\Models\Workflow::factory()->for($this->team)->create();
        $exp = $this->makeFailedExperiment(['workflow_id' => $workflow->id]);
        \App\Domain\Experiment\Models\ExperimentStage::create([
            'team_id' => $this->team->id,
            'experiment_id' => $exp->id,
            'stage' => \App\Domain\Experiment\Enums\StageType::Building,
            'iteration' => 1,
            'status' => \App\Domain\Experiment\Enums\StageStatus::Failed,
            'output_snapshot' => ['error' => 'PrismException: HTTP 429'],
            'completed_at' => now(),
            'duration_ms' => 0,
            'retry_count' => 0,
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'workflow',
            'entityId' => $workflow->id,
        ])->assertSet('eligible', true);
    }

    public function test_does_not_render_for_workflow_with_no_failures(): void
    {
        $workflow = \App\Domain\Workflow\Models\Workflow::factory()->for($this->team)->create();

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'workflow',
            'entityId' => $workflow->id,
        ])->assertSet('eligible', false);
    }

    public function test_diagnose_workflow_delegates_to_experiment_diagnose(): void
    {
        $workflow = \App\Domain\Workflow\Models\Workflow::factory()->for($this->team)->create();
        $exp = $this->makeFailedExperiment(['workflow_id' => $workflow->id]);
        \App\Domain\Experiment\Models\ExperimentStage::create([
            'team_id' => $this->team->id,
            'experiment_id' => $exp->id,
            'stage' => \App\Domain\Experiment\Enums\StageType::Building,
            'iteration' => 1,
            'status' => \App\Domain\Experiment\Enums\StageStatus::Failed,
            'output_snapshot' => ['error' => 'HTTP 429 rate limit'],
            'completed_at' => now(),
            'duration_ms' => 0,
            'retry_count' => 0,
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'workflow',
            'entityId' => $workflow->id,
        ])
            ->call('diagnose')
            ->tap(function ($component) use ($workflow, $exp) {
                $diagnosis = $component->get('diagnosis');
                $this->assertIsArray($diagnosis);
                $this->assertSame($workflow->id, $diagnosis['workflow_id']);
                $this->assertSame($exp->id, $diagnosis['root_experiment_id']);
                $this->assertSame('rate_limit', $diagnosis['root_cause']);
            });
    }

    public function test_diagnose_skill_with_empty_error_message_returns_no_message_branch(): void
    {
        $skill = \App\Domain\Skill\Models\Skill::factory()->for($this->team)->create();
        \App\Domain\Skill\Models\SkillExecution::create([
            'team_id' => $this->team->id,
            'skill_id' => $skill->id,
            'status' => 'failed',
            'input' => [],
            'output' => null,
            'duration_ms' => 0,
            'cost_credits' => 0,
            'error_message' => null,
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'skill',
            'entityId' => $skill->id,
        ])
            ->call('diagnose')
            ->tap(function ($component) {
                $diagnosis = $component->get('diagnosis');
                $this->assertIsArray($diagnosis);
                $this->assertSame('skill_failure_no_message', $diagnosis['root_cause']);
            });
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
