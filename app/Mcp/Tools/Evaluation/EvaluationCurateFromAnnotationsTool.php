<?php

namespace App\Mcp\Tools\Evaluation;

use App\Domain\Assistant\Enums\AnnotationRating;
use App\Domain\Evaluation\Actions\CurateAssistantEvalsAction;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class EvaluationCurateFromAnnotationsTool extends Tool
{
    protected string $name = 'evaluation_curate_from_annotations';

    protected string $description = 'Build a regression-ready evaluation dataset from production assistant traces. Pulls annotated messages (rated thumbs-up by default) from the last N days, pairs each with the preceding user prompt, and creates EvaluationCases that can be rerun through a new prompt/model to catch quality drops.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Name for the new dataset, e.g. "Assistant regression — March 2026"')->required(),
            'window_days' => $schema->integer()->description('How far back to pull annotated messages (default 30, max 180)')->default(30),
            'limit' => $schema->integer()->description('Max number of cases (default 50, max 500)')->default(50),
            'rating_filter' => $schema->string()
                ->description('Which annotations to include: positive | negative | any (no filter)')
                ->enum(['positive', 'negative', 'any'])
                ->default('positive'),
            'description' => $schema->string()->description('Optional description override for the dataset')->nullable(),
        ];
    }

    public function handle(Request $request, CurateAssistantEvalsAction $action): Response
    {
        $user = auth()->user();
        if (! $user || ! $user->current_team_id) {
            return Response::error('Authentication or team context missing');
        }

        $name = trim((string) $request->get('name', ''));
        if ($name === '') {
            return Response::error('name is required');
        }

        $ratingRaw = (string) $request->get('rating_filter', 'positive');
        $rating = match ($ratingRaw) {
            'positive' => AnnotationRating::Positive,
            'negative' => AnnotationRating::Negative,
            'any' => null,
            default => AnnotationRating::Positive,
        };

        $windowDays = max(1, min(180, (int) $request->get('window_days', 30)));
        $limit = max(1, min(500, (int) $request->get('limit', 50)));
        $description = $request->get('description');

        try {
            $dataset = $action->execute(
                teamId: $user->current_team_id,
                name: $name,
                windowDays: $windowDays,
                limit: $limit,
                ratingFilter: $rating,
                description: is_string($description) && $description !== '' ? $description : null,
            );
        } catch (\Throwable $e) {
            return Response::error('Curation failed: '.$e->getMessage());
        }

        $dataset = $dataset->fresh();

        return Response::text(json_encode([
            'dataset_id' => $dataset->id,
            'name' => $dataset->name,
            'description' => $dataset->description,
            'case_count' => $dataset->case_count,
            'rating_filter' => $rating->value ?? 'any',
            'window_days' => $windowDays,
            'next_step' => 'Run `evaluation_run` with this dataset_id + a new provider/model/prompt to compare outputs.',
        ]));
    }
}
