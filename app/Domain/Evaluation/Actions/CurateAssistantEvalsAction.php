<?php

namespace App\Domain\Evaluation\Actions;

use App\Domain\Assistant\Enums\AnnotationRating;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Assistant\Models\MessageAnnotation;
use App\Domain\Evaluation\Models\EvaluationDataset;
use Illuminate\Support\Facades\Log;

/**
 * Curate an evaluation dataset from production assistant traces.
 *
 * Strategy: pull annotated assistant messages (rated positive by default) from
 * the rolling window, pair each with the preceding user message in the same
 * conversation, and use `content`/`correction` as the golden answer.
 *
 * Use case: regression-gate future prompt/model changes — "run my last
 * 50 thumbs-up conversations through the new config and check output quality
 * didn't drop." Mirrors Pydantic Evals' "curate from traces" pattern.
 */
final class CurateAssistantEvalsAction
{
    public function __construct(
        private readonly CreateEvaluationDatasetAction $createDataset,
    ) {}

    public function execute(
        string $teamId,
        string $name,
        int $windowDays = 30,
        int $limit = 50,
        ?AnnotationRating $ratingFilter = AnnotationRating::Positive,
        ?string $description = null,
    ): EvaluationDataset {
        $since = now()->subDays($windowDays);

        $query = MessageAnnotation::query()
            ->where('team_id', $teamId)
            ->where('created_at', '>=', $since)
            ->with(['message:id,conversation_id,role,content,tool_calls,created_at'])
            ->orderByDesc('created_at')
            ->limit(max(1, min(500, $limit)));

        if ($ratingFilter !== null) {
            $query->where('rating', $ratingFilter->value);
        }

        $annotations = $query->get();

        $cases = [];
        $seenMessageIds = [];

        foreach ($annotations as $annotation) {
            $message = $annotation->message;
            if (! $message || $message->role !== 'assistant' || isset($seenMessageIds[$message->id])) {
                continue;
            }
            $seenMessageIds[$message->id] = true;

            $userInput = $this->preceedingUserInput($message);
            if ($userInput === null) {
                continue;
            }

            $expected = is_string($annotation->correction) && $annotation->correction !== ''
                ? $annotation->correction
                : (string) $message->content;
            if (trim($expected) === '') {
                continue;
            }

            $cases[] = [
                'input' => $userInput,
                'expected_output' => $expected,
                'context' => null,
                'metadata' => [
                    'source' => 'assistant_annotation',
                    'source_message_id' => $message->id,
                    'conversation_id' => $message->conversation_id,
                    'annotation_id' => $annotation->id,
                    'annotation_rating' => $annotation->rating->value,
                    'has_correction' => $annotation->correction !== null && $annotation->correction !== '',
                    'tool_calls_count' => is_array($message->tool_calls) ? count($message->tool_calls) : 0,
                    'captured_at' => now()->toIso8601String(),
                ],
            ];
        }

        if ($cases === []) {
            Log::info('CurateAssistantEvalsAction: no annotated cases in window, creating empty dataset', [
                'team_id' => $teamId,
                'window_days' => $windowDays,
                'rating_filter' => $ratingFilter?->value,
            ]);
        }

        return $this->createDataset->execute(
            teamId: $teamId,
            name: $name,
            description: $description ?? sprintf(
                'Curated from %d annotated assistant messages (last %d days, rating=%s).',
                count($cases),
                $windowDays,
                $ratingFilter->value ?? 'any',
            ),
            cases: $cases,
        );
    }

    private function preceedingUserInput(AssistantMessage $assistantMessage): ?string
    {
        $user = AssistantMessage::withoutGlobalScopes()
            ->where('conversation_id', $assistantMessage->conversation_id)
            ->where('role', 'user')
            ->where('created_at', '<', $assistantMessage->created_at)
            ->orderByDesc('created_at')
            ->first(['id', 'content']);

        if (! $user) {
            return null;
        }
        $input = trim((string) $user->content);

        return $input === '' ? null : $input;
    }
}
