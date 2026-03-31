<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\ExecuteSkillAction;
use App\Domain\Skill\Models\Skill;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecuteSkillErrorMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

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
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        CreditLedger::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'type' => LedgerType::Purchase,
            'amount' => 100000,
            'balance_after' => 100000,
            'description' => 'Test balance',
        ]);
    }

    public function test_skill_execution_with_empty_exception_message_produces_meaningful_error(): void
    {
        // Mock the AI gateway to throw an exception with empty message
        $gateway = $this->mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->andThrow(new \RuntimeException(''));

        $skill = Skill::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'name' => 'Chat Completion',
            'slug' => 'chat-completion',
            'type' => 'llm',
            'status' => 'active',
            'system_prompt' => 'You are a helpful assistant.',
            'input_schema' => [
                'properties' => [
                    'task' => ['type' => 'string', 'required' => true],
                ],
            ],
            'configuration' => [],
        ]);

        $action = app(ExecuteSkillAction::class);

        $result = $action->execute(
            skill: $skill,
            input: ['goal' => 'Test goal', 'task' => 'Test task'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        // The error message should NOT be empty
        $this->assertNotEmpty($result['execution']->error_message);
        $this->assertStringContainsString('RuntimeException', $result['execution']->error_message);
        $this->assertNull($result['output']);
    }

    public function test_skill_execution_with_real_exception_message_preserves_it(): void
    {
        $gateway = $this->mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->andThrow(new \RuntimeException('API rate limit exceeded'));

        $skill = Skill::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'name' => 'Chat Completion',
            'slug' => 'chat-completion-2',
            'type' => 'llm',
            'status' => 'active',
            'system_prompt' => 'You are a helpful assistant.',
            'input_schema' => [],
            'configuration' => [],
        ]);

        $action = app(ExecuteSkillAction::class);

        $result = $action->execute(
            skill: $skill,
            input: ['task' => 'Test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('API rate limit exceeded', $result['execution']->error_message);
    }
}
