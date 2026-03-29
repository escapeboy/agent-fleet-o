<?php

namespace Tests\Feature\Domain\Assistant;

use App\Domain\Assistant\Actions\ReviewAssistantConversationAction;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ReviewAssistantConversationTest extends TestCase
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
    }

    private function makeFakeGateway(array $dimensions = []): AiGatewayInterface
    {
        $defaults = [
            'completeness' => 8,
            'ambiguity_resolution' => 7,
            'sycophancy_detected' => 9,
            'goal_alignment' => 8,
            'question_quality' => 7,
        ];

        $dims = array_merge($defaults, $dimensions);

        $mock = Mockery::mock(AiGatewayInterface::class);
        $mock->shouldReceive('complete')
            ->andReturn(new AiResponseDTO(
                content: json_encode([
                    ...$dims,
                    'flags' => ['minor_tangent'],
                    'summary' => 'The conversation was generally well-structured and goal-aligned.',
                ]),
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 500, completionTokens: 200, costCredits: 10),
                provider: 'anthropic',
                model: 'claude-haiku-4-5-20251001',
                latencyMs: 200,
            ));

        $this->app->instance(AiGatewayInterface::class, $mock);

        return $mock;
    }

    private function createConversationWithMessages(int $count = 10): AssistantConversation
    {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test Conversation',
        ]);

        for ($i = 0; $i < $count; $i++) {
            AssistantMessage::create([
                'conversation_id' => $conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message $i content",
                'created_at' => now()->addSeconds($i),
            ]);
        }

        return $conversation;
    }

    public function test_authenticated_user_can_post_to_review_endpoint(): void
    {
        $this->makeFakeGateway();
        Sanctum::actingAs($this->user);

        $conversation = $this->createConversationWithMessages();

        $response = $this->postJson("/api/v1/assistant/conversations/{$conversation->id}/review");

        $response->assertOk();
        $response->assertJsonStructure([
            'review' => [
                'score',
                'dimensions',
                'flags',
                'summary',
            ],
        ]);
    }

    public function test_review_is_stored_on_conversation(): void
    {
        $this->makeFakeGateway();
        Sanctum::actingAs($this->user);

        $conversation = $this->createConversationWithMessages();

        $this->assertNull($conversation->fresh()->review);

        $this->postJson("/api/v1/assistant/conversations/{$conversation->id}/review");

        $this->assertNotNull($conversation->fresh()->review);
    }

    public function test_review_has_expected_structure(): void
    {
        $this->makeFakeGateway();
        Sanctum::actingAs($this->user);

        $conversation = $this->createConversationWithMessages();

        $response = $this->postJson("/api/v1/assistant/conversations/{$conversation->id}/review");

        $review = $response->json('review');

        $this->assertArrayHasKey('score', $review);
        $this->assertArrayHasKey('dimensions', $review);
        $this->assertArrayHasKey('flags', $review);
        $this->assertArrayHasKey('summary', $review);

        $this->assertIsInt($review['score']);
        $this->assertGreaterThanOrEqual(0, $review['score']);
        $this->assertLessThanOrEqual(100, $review['score']);

        $this->assertArrayHasKey('completeness', $review['dimensions']);
        $this->assertArrayHasKey('ambiguity_resolution', $review['dimensions']);
        $this->assertArrayHasKey('sycophancy_detected', $review['dimensions']);
        $this->assertArrayHasKey('goal_alignment', $review['dimensions']);
        $this->assertArrayHasKey('question_quality', $review['dimensions']);

        $this->assertIsArray($review['flags']);
        $this->assertIsString($review['summary']);
    }

    public function test_review_score_is_computed_as_average_times_ten(): void
    {
        $this->makeFakeGateway([
            'completeness' => 10,
            'ambiguity_resolution' => 10,
            'sycophancy_detected' => 10,
            'goal_alignment' => 10,
            'question_quality' => 10,
        ]);
        Sanctum::actingAs($this->user);

        $conversation = $this->createConversationWithMessages();

        $response = $this->postJson("/api/v1/assistant/conversations/{$conversation->id}/review");

        $response->assertOk();
        $review = $response->json('review');
        $this->assertNotContains('review_failed', $review['flags'] ?? []);
        $this->assertEquals(100, $review['score']);
    }

    public function test_action_returns_empty_review_when_no_messages(): void
    {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Empty',
        ]);

        $action = new ReviewAssistantConversationAction(
            Mockery::mock(AiGatewayInterface::class),
        );

        $review = $action->execute($conversation);

        $this->assertEquals(0, $review['score']);
        $this->assertContains('no_messages', $review['flags']);
    }

    public function test_another_team_member_cannot_review_foreign_conversation(): void
    {
        Sanctum::actingAs($this->user);

        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);

        $otherUser = User::factory()->create(['current_team_id' => $otherTeam->id]);
        $otherTeam->users()->attach($otherUser, ['role' => 'owner']);

        $foreignConversation = AssistantConversation::create([
            'team_id' => $otherTeam->id,
            'user_id' => $otherUser->id,
            'title' => 'Foreign',
        ]);

        $response = $this->postJson("/api/v1/assistant/conversations/{$foreignConversation->id}/review");

        // Cross-team access is blocked (403 from authorization check or 404 from TeamScope)
        $this->assertContains($response->status(), [403, 404]);
    }
}
