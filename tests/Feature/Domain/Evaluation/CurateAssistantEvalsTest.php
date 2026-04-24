<?php

namespace Tests\Feature\Domain\Evaluation;

use App\Domain\Assistant\Enums\AnnotationRating;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Assistant\Models\MessageAnnotation;
use App\Domain\Evaluation\Actions\CurateAssistantEvalsAction;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CurateAssistantEvalsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Curate Team',
            'slug' => 'curate-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
    }

    public function test_positive_annotations_become_eval_cases(): void
    {
        $this->seedAnnotatedExchange('What is 2+2?', 'It is 4.', AnnotationRating::Positive);
        $this->seedAnnotatedExchange('Capital of France?', 'Paris.', AnnotationRating::Positive);

        $dataset = app(CurateAssistantEvalsAction::class)->execute(
            teamId: $this->team->id,
            name: 'Test Dataset',
        );

        $this->assertSame(2, $dataset->case_count);
        $cases = $dataset->cases()->orderBy('created_at')->get();
        $this->assertCount(2, $cases);
        $inputs = $cases->pluck('input')->all();
        $this->assertContains('What is 2+2?', $inputs);
        $this->assertContains('Capital of France?', $inputs);
    }

    public function test_correction_overrides_assistant_content_as_expected_output(): void
    {
        $this->seedAnnotatedExchange(
            userInput: 'Count primes under 10',
            assistantReply: 'There are 5: 2, 3, 5, 7, 11',
            rating: AnnotationRating::Negative,
            correction: 'There are 4: 2, 3, 5, 7',
        );

        $dataset = app(CurateAssistantEvalsAction::class)->execute(
            teamId: $this->team->id,
            name: 'Corrected Dataset',
            ratingFilter: AnnotationRating::Negative,
        );

        $this->assertSame(1, $dataset->case_count);
        $case = $dataset->cases()->first();
        $this->assertSame('There are 4: 2, 3, 5, 7', $case->expected_output);
        $this->assertTrue($case->metadata['has_correction']);
    }

    public function test_negative_rating_filter_excludes_positive_annotations(): void
    {
        $this->seedAnnotatedExchange('A', 'B', AnnotationRating::Positive);
        $this->seedAnnotatedExchange('C', 'D', AnnotationRating::Negative);

        $dataset = app(CurateAssistantEvalsAction::class)->execute(
            teamId: $this->team->id,
            name: 'Negatives only',
            ratingFilter: AnnotationRating::Negative,
        );

        $this->assertSame(1, $dataset->case_count);
    }

    public function test_any_rating_filter_includes_both(): void
    {
        $this->seedAnnotatedExchange('A', 'B', AnnotationRating::Positive);
        $this->seedAnnotatedExchange('C', 'D', AnnotationRating::Negative);

        $dataset = app(CurateAssistantEvalsAction::class)->execute(
            teamId: $this->team->id,
            name: 'Everything',
            ratingFilter: null,
        );

        $this->assertSame(2, $dataset->case_count);
    }

    public function test_window_days_filters_old_annotations(): void
    {
        $this->seedAnnotatedExchange('Old', 'reply', AnnotationRating::Positive, createdDaysAgo: 45);
        $this->seedAnnotatedExchange('New', 'reply', AnnotationRating::Positive, createdDaysAgo: 5);

        $dataset = app(CurateAssistantEvalsAction::class)->execute(
            teamId: $this->team->id,
            name: 'Recent',
            windowDays: 30,
        );

        $this->assertSame(1, $dataset->case_count);
        $this->assertSame('New', $dataset->cases()->first()->input);
    }

    public function test_empty_result_creates_empty_dataset(): void
    {
        $dataset = app(CurateAssistantEvalsAction::class)->execute(
            teamId: $this->team->id,
            name: 'Empty',
        );

        $this->assertSame(0, $dataset->case_count);
        $this->assertStringContainsString('0 annotated', $dataset->description);
    }

    public function test_assistant_message_without_preceding_user_message_is_skipped(): void
    {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => null,
        ]);

        // Orphan assistant message — no preceding user message in this conversation.
        $assistantMsg = AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Orphan reply',
            'metadata' => [],
            'created_at' => now(),
        ]);
        MessageAnnotation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'message_id' => $assistantMsg->id,
            'rating' => AnnotationRating::Positive->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dataset = app(CurateAssistantEvalsAction::class)->execute(
            teamId: $this->team->id,
            name: 'Orphan test',
        );

        $this->assertSame(0, $dataset->case_count);
    }

    private function seedAnnotatedExchange(
        string $userInput,
        string $assistantReply,
        AnnotationRating $rating,
        ?string $correction = null,
        int $createdDaysAgo = 1,
    ): void {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => null,
        ]);

        $userCreatedAt = now()->subDays($createdDaysAgo)->subMinutes(2);
        $assistantCreatedAt = now()->subDays($createdDaysAgo);

        AssistantMessage::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userInput,
            'metadata' => [],
            'created_at' => $userCreatedAt,
        ]);

        $assistantMsg = AssistantMessage::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $assistantReply,
            'metadata' => [],
            'created_at' => $assistantCreatedAt,
        ]);

        $annotation = MessageAnnotation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'message_id' => $assistantMsg->id,
            'rating' => $rating->value,
            'correction' => $correction,
        ]);
        // created_at/updated_at are not fillable — backdate explicitly via forceFill.
        $annotation->forceFill([
            'created_at' => $assistantCreatedAt,
            'updated_at' => $assistantCreatedAt,
        ])->saveQuietly();
    }
}
