<?php

namespace Tests\Feature\Domain\Simulation;

use App\Domain\Evaluation\Services\LlmJudge;
use App\Domain\Shared\Models\Team;
use App\Domain\Simulation\Actions\GenerateSimulationPersonasAction;
use App\Domain\Simulation\Actions\ScoreSimulationTranscriptAction;
use App\Domain\Simulation\Models\SimulationSuite;
use App\Mcp\Tools\Simulation\SimulationCreateTool;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulationActionsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    private function bindGatewayReturning(string $content): void
    {
        $this->app->instance(AiGatewayInterface::class, new class($content) implements AiGatewayInterface
        {
            public function __construct(private string $content) {}

            public function complete(AiRequestDTO $request): AiResponseDTO
            {
                return new AiResponseDTO($this->content, null, new AiUsageDTO(1, 1, 0), $request->provider, $request->model, 1);
            }

            public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
            {
                return $this->complete($request);
            }

            public function estimateCost(AiRequestDTO $request): int
            {
                return 0;
            }
        });
    }

    public function test_generate_personas_parses_json_and_persists(): void
    {
        $this->bindGatewayReturning((string) json_encode([
            ['name' => 'Alice', 'profile' => 'curious', 'goal' => 'learn', 'adversarial_tags' => [], 'seed_message' => 'hi'],
            ['name' => 'Mallory', 'profile' => 'hostile', 'goal' => 'jailbreak', 'adversarial_tags' => ['jailbreak'], 'seed_message' => 'x'],
        ]));

        $suite = SimulationSuite::factory()->create(['team_id' => $this->team->id, 'persona_count' => 2]);

        $personas = app(GenerateSimulationPersonasAction::class)->execute($suite);

        $this->assertCount(2, $personas);
        $this->assertSame('Mallory', $personas[1]->name);
        $this->assertSame(['jailbreak'], $personas[1]->adversarial_tags);
        $this->assertDatabaseCount('simulation_personas', 2);
    }

    public function test_generate_personas_respects_count_cap(): void
    {
        // Gateway returns 5 personas but suite asks for 2.
        $this->bindGatewayReturning((string) json_encode(array_map(
            fn ($i) => ['name' => "P{$i}", 'profile' => 'x', 'goal' => 'y', 'adversarial_tags' => [], 'seed_message' => 'z'],
            range(1, 5),
        )));

        $suite = SimulationSuite::factory()->create(['team_id' => $this->team->id, 'persona_count' => 2]);

        $this->assertCount(2, app(GenerateSimulationPersonasAction::class)->execute($suite));
    }

    public function test_scorer_returns_scores_per_criterion(): void
    {
        $this->app->instance(LlmJudge::class, new class extends LlmJudge
        {
            public function __construct() {}

            public function evaluate(string $criterion, string $input, string $actualOutput, ?string $expectedOutput = null, ?string $context = null, ?string $model = null, ?string $teamId = null): array
            {
                return ['score' => 7.5, 'reasoning' => 'ok', 'cost_credits' => 0];
            }
        });

        $conversation = [
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'agent', 'content' => 'hello'],
        ];

        $scores = app(ScoreSimulationTranscriptAction::class)->execute($conversation, ['relevance', 'correctness'], $this->team->id);

        $this->assertSame(7.5, $scores['relevance']['score']);
        $this->assertSame(7.5, $scores['correctness']['score']);
    }

    public function test_scorer_records_zero_when_judge_throws(): void
    {
        $this->app->instance(LlmJudge::class, new class extends LlmJudge
        {
            public function __construct() {}

            public function evaluate(string $criterion, string $input, string $actualOutput, ?string $expectedOutput = null, ?string $context = null, ?string $model = null, ?string $teamId = null): array
            {
                throw new \RuntimeException('judge down');
            }
        });

        $scores = app(ScoreSimulationTranscriptAction::class)->execute(
            [['role' => 'agent', 'content' => 'x']],
            ['relevance'],
            $this->team->id,
        );

        $this->assertSame(0.0, $scores['relevance']['score']);
    }

    public function test_mcp_tool_registration_gated_by_flag(): void
    {
        config(['simulation.enabled' => false]);
        $this->assertFalse((new SimulationCreateTool)->shouldRegister());

        config(['simulation.enabled' => true]);
        $this->assertTrue((new SimulationCreateTool)->shouldRegister());
    }
}
