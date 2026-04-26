<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Models\WorkflowSnapshot;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Livewire\Experiments\WorkflowTimeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MakesFailedExperiments;
use Tests\TestCase;

class WorkflowTimelineReplayTest extends TestCase
{
    use MakesFailedExperiments;
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Experiment $experiment;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Replay Team',
            'slug' => 'replay-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        $this->experiment = $this->makeFailedExperiment();
        $this->agent = Agent::factory()->for($this->team)->create();

        $this->app->instance(AiGatewayInterface::class, new ReplayStubGateway);
    }

    private function makeStepAndSnapshot(?string $agentId, array $stepInput = []): WorkflowSnapshot
    {
        $stepId = $agentId === null ? null : PlaybookStep::create([
            'team_id' => $this->team->id,
            'experiment_id' => $this->experiment->id,
            'agent_id' => $agentId,
            'order' => 1,
            'type' => 'agent',
            'name' => 'Test step',
            'input_data' => [],
            'output_data' => [],
            'status' => 'completed',
        ])->id;

        return WorkflowSnapshot::create([
            'team_id' => $this->team->id,
            'experiment_id' => $this->experiment->id,
            'playbook_step_id' => $stepId,
            'workflow_node_id' => null,
            'event_type' => 'step_completed',
            'sequence' => 1,
            'graph_state' => ['nodes' => []],
            'step_input' => $stepInput,
            'step_output' => ['result' => 'old'],
            'metadata' => [],
            'duration_from_start_ms' => 100,
            'created_at' => now(),
        ]);
    }

    public function test_open_replay_resolves_agent_from_step(): void
    {
        $snapshot = $this->makeStepAndSnapshot($this->agent->id, ['user_message' => 'original input']);

        Livewire::test(WorkflowTimeline::class, ['experimentId' => $this->experiment->id])
            ->call('openReplay', $snapshot->id)
            ->assertSet('replayingFor', $snapshot->id)
            ->assertSet('replayAgentId', $this->agent->id)
            ->assertSet('replayInput', 'original input')
            ->assertSet('replayError', '');
    }

    public function test_open_replay_falls_back_when_no_agent(): void
    {
        $snapshot = $this->makeStepAndSnapshot(null);

        Livewire::test(WorkflowTimeline::class, ['experimentId' => $this->experiment->id])
            ->call('openReplay', $snapshot->id)
            ->assertSet('replayingFor', '')
            ->assertSet('replayAgentId', null)
            ->tap(function ($component) {
                $this->assertStringContainsString(
                    'no playbook step',
                    strtolower((string) $component->get('replayError')),
                );
            });
    }

    public function test_execute_replay_calls_dry_run_and_stores_output(): void
    {
        $snapshot = $this->makeStepAndSnapshot($this->agent->id, ['user_message' => 'hi']);

        Livewire::test(WorkflowTimeline::class, ['experimentId' => $this->experiment->id])
            ->call('openReplay', $snapshot->id)
            ->call('executeReplay')
            ->assertSet('replayError', '')
            ->tap(function ($component) {
                $result = $component->get('replayResult');
                $this->assertIsArray($result);
                $this->assertStringContainsString('stub-replay', $result['output']);
                $this->assertNotEmpty($result['model']);
            });
    }

    public function test_execute_replay_with_empty_input_returns_validation_error(): void
    {
        $snapshot = $this->makeStepAndSnapshot($this->agent->id);

        Livewire::test(WorkflowTimeline::class, ['experimentId' => $this->experiment->id])
            ->call('openReplay', $snapshot->id)
            ->set('replayInput', '   ')
            ->call('executeReplay')
            ->tap(function ($component) {
                $this->assertSame(null, $component->get('replayResult'));
                $this->assertStringContainsString('cannot be empty', (string) $component->get('replayError'));
            });
    }

    public function test_close_replay_resets_state(): void
    {
        $snapshot = $this->makeStepAndSnapshot($this->agent->id, ['user_message' => 'x']);

        Livewire::test(WorkflowTimeline::class, ['experimentId' => $this->experiment->id])
            ->call('openReplay', $snapshot->id)
            ->call('closeReplay')
            ->assertSet('replayingFor', '')
            ->assertSet('replayAgentId', null)
            ->assertSet('replayInput', '')
            ->assertSet('replayResult', null);
    }

    public function test_replay_uses_system_prompt_override_when_provided(): void
    {
        $snapshot = $this->makeStepAndSnapshot($this->agent->id, ['user_message' => 'hi']);

        $capturing = new CapturingReplayGateway;
        $this->app->instance(AiGatewayInterface::class, $capturing);

        Livewire::test(WorkflowTimeline::class, ['experimentId' => $this->experiment->id])
            ->call('openReplay', $snapshot->id)
            ->set('replaySystemPromptOverride', 'YOU ARE A REVIEWER')
            ->call('executeReplay');

        $this->assertSame('YOU ARE A REVIEWER', $capturing->lastSystemPrompt);
    }
}

class ReplayStubGateway implements AiGatewayInterface
{
    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        return new AiResponseDTO(
            content: 'stub-replay: '.$request->userPrompt,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 5, costCredits: 1),
            provider: $request->provider,
            model: $request->model,
            latencyMs: 50,
        );
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        return $this->complete($request);
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        return 1;
    }
}

class CapturingReplayGateway implements AiGatewayInterface
{
    public ?string $lastSystemPrompt = null;

    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        $this->lastSystemPrompt = $request->systemPrompt;

        return new AiResponseDTO(
            content: 'ok',
            parsedOutput: null,
            usage: new AiUsageDTO(0, 0, 0),
            provider: $request->provider,
            model: $request->model,
            latencyMs: 0,
        );
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        return $this->complete($request);
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        return 0;
    }
}
