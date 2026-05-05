<?php

namespace Tests\Feature\Domain\Approval;

use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Events\ActionProposalExecuted;
use App\Domain\Approval\Listeners\AppendExecutionResultToConversation;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppendExecutionResultTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private AssistantConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test',
        ]);
    }

    public function test_appends_assistant_message_when_execution_succeeds(): void
    {
        $proposal = $this->makeProposal(withConversation: true);
        $proposal->update([
            'status' => ActionProposalStatus::Executed,
            'executed_at' => now(),
            'execution_result' => ['success' => true, 'agent_id' => 'abc'],
        ]);

        $listener = new AppendExecutionResultToConversation;
        $listener->handle(new ActionProposalExecuted($proposal->fresh(), true));

        $message = AssistantMessage::where('conversation_id', $this->conversation->id)
            ->latest('created_at')->first();

        $this->assertNotNull($message);
        $this->assertSame('assistant', $message->role);
        $this->assertStringContainsString('Action approved and executed', $message->content);
        $this->assertStringContainsString($proposal->id, $message->content);
        $this->assertStringContainsString('agent_id', $message->content);
        $this->assertSame('action_proposal_result', $message->metadata['kind']);
        $this->assertTrue($message->metadata['succeeded']);
    }

    public function test_appends_assistant_message_when_execution_fails(): void
    {
        $proposal = $this->makeProposal(withConversation: true);
        $proposal->update([
            'status' => ActionProposalStatus::ExecutionFailed,
            'executed_at' => now(),
            'execution_error' => 'Tool not found',
        ]);

        $listener = new AppendExecutionResultToConversation;
        $listener->handle(new ActionProposalExecuted($proposal->fresh(), false));

        $message = AssistantMessage::where('conversation_id', $this->conversation->id)
            ->latest('created_at')->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('execution failed', $message->content);
        $this->assertStringContainsString('Tool not found', $message->content);
        $this->assertFalse($message->metadata['succeeded']);
    }

    public function test_no_op_when_proposal_has_no_conversation(): void
    {
        $proposal = $this->makeProposal(withConversation: false);
        $proposal->update([
            'status' => ActionProposalStatus::Executed,
            'execution_result' => ['ok' => true],
        ]);

        $before = AssistantMessage::count();

        $listener = new AppendExecutionResultToConversation;
        $listener->handle(new ActionProposalExecuted($proposal->fresh(), true));

        $this->assertSame($before, AssistantMessage::count());
    }

    private function makeProposal(bool $withConversation): ActionProposal
    {
        $payload = [
            'tool' => 'agent_delete',
            'positional_args' => ['agent-abc'],
        ];
        if ($withConversation) {
            $payload['conversation_id'] = $this->conversation->id;
        }

        return app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Delete agent',
            payload: $payload,
            userId: $this->user->id,
        );
    }
}
