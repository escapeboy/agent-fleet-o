<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Evolution;

use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Enums\EvolutionType;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Evolution\Services\GEPAOptimizer;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GEPAOptimizerTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;
    private Skill $skill;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'GEPA Test Team',
            'slug' => 'gepa-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->skill = Skill::factory()->create([
            'team_id' => $this->team->id,
            'system_prompt' => 'You are a helpful assistant.',
        ]);
    }

    public function test_run_with_fewer_than_5_executions_returns_empty_collection(): void
    {
        for ($i = 0; $i < 3; $i++) {
            SkillExecution::withoutGlobalScopes()->create([
                'skill_id' => $this->skill->id,
                'team_id' => $this->team->id,
                'status' => 'completed',
                'quality_score' => 0.8,
                'input' => ['text' => 'test'],
                'output' => ['result' => 'ok'],
            ]);
        }

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');

        $optimizer = new GEPAOptimizer($gateway);
        $result = $optimizer->run($this->skill);

        $this->assertCount(0, $result);
    }

    public function test_run_with_sufficient_executions_creates_evolution_proposals(): void
    {
        for ($i = 0; $i < 20; $i++) {
            SkillExecution::withoutGlobalScopes()->create([
                'skill_id' => $this->skill->id,
                'team_id' => $this->team->id,
                'status' => 'completed',
                'quality_score' => round(0.5 + ($i % 5) * 0.1, 1),
                'input' => ['text' => 'test'],
                'output' => ['result' => 'ok'],
            ]);
        }

        $variants = [
            ['system_prompt' => 'Variant 1 prompt', 'strategy' => 'add_examples', 'reasoning' => 'r1'],
            ['system_prompt' => 'Variant 2 prompt', 'strategy' => 'rephrase_goal', 'reasoning' => 'r2'],
            ['system_prompt' => 'Variant 3 prompt', 'strategy' => 'add_constraints', 'reasoning' => 'r3'],
            ['system_prompt' => 'Variant 4 prompt', 'strategy' => 'simplify', 'reasoning' => 'r4'],
            ['system_prompt' => 'Variant 5 prompt', 'strategy' => 'chain_of_thought', 'reasoning' => 'r5'],
        ];

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn(new AiResponseDTO(
                content: json_encode(['variants' => $variants]),
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 100, completionTokens: 200, costCredits: 5),
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                latencyMs: 500,
            ));

        $optimizer = new GEPAOptimizer($gateway);
        $result = $optimizer->run($this->skill, populationSize: 5);

        $this->assertCount(5, $result);
    }

    public function test_created_proposals_have_skill_mutation_evolution_type(): void
    {
        for ($i = 0; $i < 10; $i++) {
            SkillExecution::withoutGlobalScopes()->create([
                'skill_id' => $this->skill->id,
                'team_id' => $this->team->id,
                'status' => 'completed',
                'quality_score' => 0.7,
                'input' => ['text' => 'test'],
                'output' => ['result' => 'ok'],
            ]);
        }

        $variants = [
            ['system_prompt' => 'Better prompt', 'strategy' => 'add_examples', 'reasoning' => 'test'],
        ];

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn(new AiResponseDTO(
                content: json_encode(['variants' => $variants]),
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 50, completionTokens: 100, costCredits: 2),
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                latencyMs: 300,
            ));

        $optimizer = new GEPAOptimizer($gateway);
        $result = $optimizer->run($this->skill, populationSize: 1);

        $this->assertCount(1, $result);
        $proposal = $result->first();
        $this->assertInstanceOf(EvolutionProposal::class, $proposal);
        $this->assertSame(EvolutionType::SkillMutation, $proposal->evolution_type);
    }

    public function test_created_proposals_have_pending_status(): void
    {
        for ($i = 0; $i < 10; $i++) {
            SkillExecution::withoutGlobalScopes()->create([
                'skill_id' => $this->skill->id,
                'team_id' => $this->team->id,
                'status' => 'completed',
                'quality_score' => 0.6,
                'input' => ['text' => 'test'],
                'output' => ['result' => 'ok'],
            ]);
        }

        $variants = [
            ['system_prompt' => 'Pending proposal prompt', 'strategy' => 'simplify', 'reasoning' => 'test'],
        ];

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn(new AiResponseDTO(
                content: json_encode(['variants' => $variants]),
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 50, completionTokens: 100, costCredits: 2),
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                latencyMs: 300,
            ));

        $optimizer = new GEPAOptimizer($gateway);
        $result = $optimizer->run($this->skill, populationSize: 1);

        $proposal = $result->first();
        $this->assertSame(EvolutionProposalStatus::Pending, $proposal->status);
    }

    public function test_created_proposals_have_correct_skill_id(): void
    {
        for ($i = 0; $i < 10; $i++) {
            SkillExecution::withoutGlobalScopes()->create([
                'skill_id' => $this->skill->id,
                'team_id' => $this->team->id,
                'status' => 'completed',
                'quality_score' => 0.75,
                'input' => ['text' => 'test'],
                'output' => ['result' => 'ok'],
            ]);
        }

        $variants = [
            ['system_prompt' => 'Skill-linked prompt', 'strategy' => 'rephrase_goal', 'reasoning' => 'test'],
        ];

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn(new AiResponseDTO(
                content: json_encode(['variants' => $variants]),
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 50, completionTokens: 100, costCredits: 2),
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                latencyMs: 300,
            ));

        $optimizer = new GEPAOptimizer($gateway);
        $result = $optimizer->run($this->skill, populationSize: 1);

        $proposal = $result->first();
        $this->assertSame($this->skill->id, $proposal->skill_id);
    }
}
