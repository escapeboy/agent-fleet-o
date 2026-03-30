<?php

namespace Tests\Feature\Domain\Evolution;

use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Evolution\Actions\ProposeCrewRestructuringAction;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProposeCrewRestructuringTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    private Crew $crew;

    private Agent $coordinator;

    private Agent $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);

        $this->coordinator = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Coordinator Agent',
            'role' => 'coordinator',
            'goal' => 'Coordinate the team',
        ]);

        $this->worker = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Worker Agent',
            'role' => 'worker',
            'goal' => 'Execute tasks',
        ]);

        $qaAgent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'QA Agent',
            'role' => 'qa',
            'goal' => 'Validate task outputs',
        ]);

        $this->crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'coordinator_agent_id' => $this->coordinator->id,
            'qa_agent_id' => $qaAgent->id,
            'name' => 'Test Crew',
        ]);
    }

    public function test_creates_an_approval_request_with_restructuring_proposal(): void
    {
        $this->seedExecutions();

        $mockLlmResponse = json_encode([
            'analysis' => [
                'bottleneck_roles' => ['coordinator'],
                'redundant_roles' => [],
                'missing_roles' => ['reviewer'],
                'coordination_failures' => ['tasks failing due to unclear ownership'],
            ],
            'proposed_changes' => [
                ['action' => 'add_role', 'role' => 'reviewer', 'rationale' => 'Need a dedicated reviewer to reduce failures'],
            ],
            'confidence' => 0.78,
            'summary' => 'The crew has a 50% failure rate. Adding a reviewer role should improve outcomes.',
        ]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn(new AiResponseDTO(
                content: $mockLlmResponse,
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 200, completionTokens: 300, costCredits: 0),
                provider: 'anthropic',
                model: 'claude-haiku-4-5-20251001',
                latencyMs: 100,
            ));

        $this->app->instance(AiGatewayInterface::class, $gateway);

        $action = app(ProposeCrewRestructuringAction::class);
        $approval = $action->execute($this->crew, $this->user->id);

        $this->assertInstanceOf(ApprovalRequest::class, $approval);
        $this->assertEquals(ApprovalStatus::Pending, $approval->status);
        $this->assertEquals($this->team->id, $approval->team_id);

        $context = $approval->context;
        $this->assertEquals('crew_restructuring', $context['type']);
        $this->assertEquals($this->crew->id, $context['crew_id']);
        $this->assertEquals($this->crew->name, $context['crew_name']);
        $this->assertIsArray($context['analysis']);
        $this->assertIsArray($context['proposed_changes']);
        $this->assertIsFloat($context['confidence']);
        $this->assertIsString($context['summary']);
        $this->assertEquals(0.78, $context['confidence']);
    }

    public function test_includes_execution_metrics_in_proposal_context(): void
    {
        $this->seedExecutions();

        $mockLlmResponse = json_encode([
            'analysis' => ['bottleneck_roles' => [], 'redundant_roles' => [], 'missing_roles' => [], 'coordination_failures' => []],
            'proposed_changes' => [],
            'confidence' => 0.5,
            'summary' => 'The crew is performing adequately.',
        ]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn(new AiResponseDTO(
                content: $mockLlmResponse,
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 100, completionTokens: 150, costCredits: 0),
                provider: 'anthropic',
                model: 'claude-haiku-4-5-20251001',
                latencyMs: 100,
            ));

        $this->app->instance(AiGatewayInterface::class, $gateway);

        $action = app(ProposeCrewRestructuringAction::class);
        $approval = $action->execute($this->crew, $this->user->id);

        $metrics = $approval->context['metrics'];
        $this->assertEquals(2, $metrics['total']);
        $this->assertEquals(1, $metrics['completed']);
        $this->assertEquals(1, $metrics['failed']);
        $this->assertEquals(0.5, $metrics['success_rate']);
    }

    public function test_handles_crew_with_no_execution_history(): void
    {
        $mockLlmResponse = json_encode([
            'analysis' => ['bottleneck_roles' => [], 'redundant_roles' => [], 'missing_roles' => [], 'coordination_failures' => []],
            'proposed_changes' => [],
            'confidence' => 0.3,
            'summary' => 'No execution history available to analyse.',
        ]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn(new AiResponseDTO(
                content: $mockLlmResponse,
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 100, completionTokens: 100, costCredits: 0),
                provider: 'anthropic',
                model: 'claude-haiku-4-5-20251001',
                latencyMs: 100,
            ));

        $this->app->instance(AiGatewayInterface::class, $gateway);

        $action = app(ProposeCrewRestructuringAction::class);
        $approval = $action->execute($this->crew, $this->user->id);

        $this->assertInstanceOf(ApprovalRequest::class, $approval);
        $this->assertEquals(0, $approval->context['metrics']['total']);
        $this->assertNull($approval->context['metrics']['success_rate']);
    }

    public function test_gracefully_handles_malformed_llm_response(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn(new AiResponseDTO(
                content: 'Sorry, I cannot analyse this crew.',
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 50, completionTokens: 20, costCredits: 0),
                provider: 'anthropic',
                model: 'claude-haiku-4-5-20251001',
                latencyMs: 100,
            ));

        $this->app->instance(AiGatewayInterface::class, $gateway);

        $action = app(ProposeCrewRestructuringAction::class);
        $approval = $action->execute($this->crew, $this->user->id);

        $this->assertInstanceOf(ApprovalRequest::class, $approval);
        $this->assertEquals(ApprovalStatus::Pending, $approval->status);
        $this->assertIsString($approval->context['summary']);
        $this->assertEquals(0.5, $approval->context['confidence']);
    }

    private function seedExecutions(): void
    {
        CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $this->crew->id,
            'goal' => 'Complete task A',
            'status' => CrewExecutionStatus::Completed,
            'duration_ms' => 5000,
        ]);

        CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $this->crew->id,
            'goal' => 'Complete task B',
            'status' => CrewExecutionStatus::Failed,
            'error_message' => 'Agent timed out',
            'duration_ms' => 10000,
        ]);
    }
}
