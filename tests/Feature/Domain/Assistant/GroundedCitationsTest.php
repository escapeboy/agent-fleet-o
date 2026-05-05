<?php

namespace Tests\Feature\Domain\Assistant;

use App\Domain\Assistant\Actions\SendAssistantMessageAction;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class GroundedCitationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Citations Team',
            'slug' => 'citations-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    public function test_inline_marker_validated_and_saved_as_citation(): void
    {
        $experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Winning experiment',
        ]);

        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => null,
        ]);

        $this->bindFakeGateway(
            replyText: "You have one experiment [[experiment:{$experiment->id}]].",
            toolResults: [
                ['toolName' => 'experiment_list', 'result' => ['id' => $experiment->id, 'title' => 'Winning experiment']],
            ],
        );

        $action = app(SendAssistantMessageAction::class);
        $action->execute(
            conversation: $conversation,
            userMessage: 'list my experiments',
            user: $this->user,
        );

        $message = $conversation->messages()->where('role', 'assistant')->latest('created_at')->first();
        $this->assertNotNull($message);

        $this->assertStringContainsString('[[1]]', $message->content);
        $this->assertStringNotContainsString("[[experiment:{$experiment->id}]]", $message->content);

        $metadata = $message->metadata;
        $this->assertArrayHasKey('citations', $metadata);
        $this->assertCount(1, $metadata['citations']);
        $this->assertSame('experiment', $metadata['citations'][0]['kind']);
        $this->assertSame($experiment->id, $metadata['citations'][0]['id']);
        $this->assertSame('Winning experiment', $metadata['citations'][0]['title']);
    }

    public function test_hallucinated_marker_is_stripped_and_no_citation_recorded(): void
    {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => null,
        ]);

        $fakeId = (string) Str::uuid();

        $this->bindFakeGateway(
            replyText: "Here is a bogus ref [[experiment:{$fakeId}]] — should be stripped.",
            toolResults: [],
        );

        $action = app(SendAssistantMessageAction::class);
        $action->execute(
            conversation: $conversation,
            userMessage: 'anything',
            user: $this->user,
        );

        $message = $conversation->messages()->where('role', 'assistant')->latest('created_at')->first();
        $this->assertStringNotContainsString('[[', $message->content);
        $this->assertStringNotContainsString($fakeId, $message->content);
        $this->assertArrayNotHasKey('citations', $message->metadata);
    }

    public function test_reply_without_markers_has_no_citations_metadata_key(): void
    {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => null,
        ]);

        $this->bindFakeGateway(
            replyText: 'Simple conversational answer, no entities.',
            toolResults: [],
        );

        $action = app(SendAssistantMessageAction::class);
        $action->execute(
            conversation: $conversation,
            userMessage: 'hello',
            user: $this->user,
        );

        $message = $conversation->messages()->where('role', 'assistant')->latest('created_at')->first();
        $this->assertSame('Simple conversational answer, no entities.', $message->content);
        $this->assertArrayNotHasKey('citations', $message->metadata);
    }

    private function bindFakeGateway(string $replyText, array $toolResults): void
    {
        $this->app->bind(AiGatewayInterface::class, function () use ($replyText, $toolResults) {
            $gateway = Mockery::mock(AiGatewayInterface::class);
            $response = new AiResponseDTO(
                content: $replyText,
                parsedOutput: null,
                usage: new AiUsageDTO(100, 50, 0.1),
                provider: 'anthropic',
                model: 'claude-sonnet-4-5',
                latencyMs: 42,
                schemaValid: true,
                cached: false,
                toolResults: $toolResults,
                steps: [],
                toolCallsCount: count($toolResults),
                stepsCount: 1,
            );
            $gateway->shouldReceive('stream')->andReturn($response);
            $gateway->shouldReceive('complete')->andReturn($response);

            return $gateway;
        });
    }
}
