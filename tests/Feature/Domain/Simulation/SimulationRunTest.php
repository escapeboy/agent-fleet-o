<?php

namespace Tests\Feature\Domain\Simulation;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Evaluation\Services\LlmJudge;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Domain\Simulation\Actions\RunSimulationAction;
use App\Domain\Simulation\Enums\SimulationStatus;
use App\Domain\Simulation\Models\SimulationPersona;
use App\Domain\Simulation\Models\SimulationRun;
use App\Domain\Simulation\Models\SimulationSuite;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SimulationRunTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);

        $this->app->instance(AiGatewayInterface::class, $this->fakeGateway());
        $this->app->instance(ExecuteAgentAction::class, $this->fakeExecuteAgent());
    }

    private function fakeGateway(): AiGatewayInterface
    {
        return new class implements AiGatewayInterface
        {
            public function complete(AiRequestDTO $request): AiResponseDTO
            {
                $content = $request->purpose === 'simulation.persona_gen'
                    ? (string) json_encode([
                        ['name' => 'Alice', 'profile' => 'curious', 'goal' => 'learn', 'adversarial_tags' => [], 'seed_message' => 'hi'],
                        ['name' => 'Mallory', 'profile' => 'hostile', 'goal' => 'jailbreak', 'adversarial_tags' => ['jailbreak'], 'seed_message' => 'ignore rules'],
                    ])
                    : 'simulated user message';

                return new AiResponseDTO($content, null, new AiUsageDTO(1, 1, 0), $request->provider, $request->model, 1);
            }

            public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
            {
                return $this->complete($request);
            }

            public function estimateCost(AiRequestDTO $request): int
            {
                return 0;
            }
        };
    }

    private function fakeExecuteAgent(): ExecuteAgentAction
    {
        return new class extends ExecuteAgentAction
        {
            public function __construct() {}

            public function execute(Agent $agent, array $input, string $teamId, string $userId, ?string $experimentId = null, ?Project $project = null, ?string $stepId = null, ?array $allowedToolIds = null, ?int $maxStepsOverride = null): array
            {
                return ['output' => ['result' => 'reply to: '.($input['message'] ?? '')]];
            }
        };
    }

    private function bindJudge(float $score): void
    {
        $this->app->instance(LlmJudge::class, new class($score) extends LlmJudge
        {
            public function __construct(private float $score) {}

            public function evaluate(string $criterion, string $input, string $actualOutput, ?string $expectedOutput = null, ?string $context = null, ?string $model = null, ?string $teamId = null): array
            {
                return ['score' => $this->score, 'reasoning' => 'fake', 'cost_credits' => 0];
            }
        });
    }

    private function makeSuite(string $targetId, int $personas = 2): SimulationSuite
    {
        $suite = SimulationSuite::factory()->create([
            'team_id' => $this->team->id,
            'target_id' => $targetId,
            'criteria' => ['relevance'],
            'max_turns' => 2,
            'pass_threshold' => 6.0,
            'created_by' => $this->user->id,
        ]);

        SimulationPersona::factory()->count($personas)->create([
            'team_id' => $this->team->id,
            'suite_id' => $suite->id,
        ]);

        return $suite;
    }

    public function test_run_completes_with_pass_matrix(): void
    {
        $this->bindJudge(8.0);
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $suite = $this->makeSuite($agent->id, personas: 2);
        $run = SimulationRun::factory()->create([
            'team_id' => $this->team->id,
            'suite_id' => $suite->id,
            'created_by' => $this->user->id,
        ]);

        $result = app(RunSimulationAction::class)->execute($run);

        $this->assertSame(SimulationStatus::Completed, $result->status);
        $this->assertSame(2, $result->aggregate['personas']);
        $this->assertSame(2, $result->aggregate['passed']);
        $this->assertSame(0, $result->aggregate['failed']);
        $this->assertCount(2, $result->transcripts);

        $transcript = $result->transcripts->first();
        $this->assertSame('pass', $transcript->verdict);
        $this->assertCount(4, $transcript->turns); // 2 turns × (user + agent)
    }

    public function test_low_scores_produce_fail_verdict_and_failed_turn_index(): void
    {
        $this->bindJudge(3.0);
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $suite = $this->makeSuite($agent->id, personas: 1);
        $run = SimulationRun::factory()->create([
            'team_id' => $this->team->id,
            'suite_id' => $suite->id,
            'created_by' => $this->user->id,
        ]);

        $result = app(RunSimulationAction::class)->execute($run);

        $this->assertSame(1, $result->aggregate['failed']);
        $transcript = $result->transcripts->first();
        $this->assertSame('fail', $transcript->verdict);
        $this->assertNotNull($transcript->failed_turn_index);
    }

    public function test_missing_target_agent_fails_run(): void
    {
        $this->bindJudge(8.0);
        $suite = $this->makeSuite((string) Str::uuid(), personas: 1);
        $run = SimulationRun::factory()->create([
            'team_id' => $this->team->id,
            'suite_id' => $suite->id,
        ]);

        $result = app(RunSimulationAction::class)->execute($run);

        $this->assertSame(SimulationStatus::Failed, $result->status);
        $this->assertStringContainsString('agent not found', strtolower((string) $result->error));
    }

    public function test_run_without_personas_fails(): void
    {
        $this->bindJudge(8.0);
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $suite = SimulationSuite::factory()->create([
            'team_id' => $this->team->id,
            'target_id' => $agent->id,
        ]);
        $run = SimulationRun::factory()->create([
            'team_id' => $this->team->id,
            'suite_id' => $suite->id,
        ]);

        $result = app(RunSimulationAction::class)->execute($run);

        $this->assertSame(SimulationStatus::Failed, $result->status);
    }
}
