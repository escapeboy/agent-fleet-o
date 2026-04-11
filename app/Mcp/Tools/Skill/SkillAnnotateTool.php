<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Actions\AnnotateSkillResponseAction;
use App\Domain\Skill\Enums\AnnotationRating;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * MCP tool: skill_annotate
 *
 * Persists a thumbs-up or thumbs-down annotation on a skill playground output.
 */
#[IsDestructive]
class SkillAnnotateTool extends Tool
{
    protected string $name = 'skill_annotate';

    protected string $description = 'Annotate a skill playground response as good or bad. Annotations are used to generate improved skill versions.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_version_id' => $schema->string()
                ->description('UUID of the SkillVersion that produced the output')
                ->required(),
            'model_id' => $schema->string()
                ->description('Model identifier that produced the output, e.g. "anthropic/claude-sonnet-4-5"')
                ->required(),
            'input' => $schema->string()
                ->description('The test input that was sent to the model')
                ->required(),
            'output' => $schema->string()
                ->description("The model's output text being annotated")
                ->required(),
            'rating' => $schema->string()
                ->description('Feedback rating: "good" or "bad"')
                ->enum(['good', 'bad'])
                ->required(),
            'note' => $schema->string()
                ->description('Optional free-text explanation for the rating'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'skill_version_id' => 'required|string',
            'model_id' => 'required|string|max:100',
            'input' => 'required|string|max:10000',
            'output' => 'required|string',
            'rating' => 'required|string|in:good,bad',
            'note' => 'nullable|string|max:1000',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        try {
            $annotation = app(AnnotateSkillResponseAction::class)->execute(
                teamId: $teamId,
                userId: auth()->id() ?? '',
                skillVersionId: $validated['skill_version_id'],
                modelId: $validated['model_id'],
                input: $validated['input'],
                output: $validated['output'],
                rating: AnnotationRating::from($validated['rating']),
                note: $validated['note'] ?? null,
            );

            return Response::text(json_encode([
                'success' => true,
                'annotation_id' => $annotation->id,
                'rating' => $annotation->rating->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
