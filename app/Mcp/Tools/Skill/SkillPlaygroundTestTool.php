<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Actions\SkillPlaygroundRunAction;
use App\Domain\Skill\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Attributes\IsIdempotent;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: skill_playground_test
 *
 * Runs a skill's prompt against one or more models and returns a run ID that
 * can be polled via Cache::get("skill_playground:{teamId}:{runId}:{modelId}").
 */
#[IsIdempotent]
class SkillPlaygroundTestTool extends Tool
{
    protected string $name = 'skill_playground_test';

    protected string $description = 'Run a skill prompt against one or more models for comparison. Returns a run_id; results are stored in cache for 300 seconds keyed as skill_playground:{teamId}:{runId}:{modelId}.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()
                ->description('UUID of the skill to test')
                ->required(),
            'input' => $schema->string()
                ->description('Test input text. Use {{variable}} syntax for placeholder substitution.')
                ->required(),
            'models' => $schema->array()
                ->description('List of model identifiers in "provider/model" format, e.g. ["anthropic/claude-sonnet-4-5", "openai/gpt-4o"]')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'skill_id' => 'required|string',
            'input' => 'required|string|max:10000',
            'models' => 'required|array|min:1|max:5',
            'models.*' => 'required|string|max:100',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $skill = Skill::find($validated['skill_id']);
        if (! $skill || $skill->team_id !== $teamId) {
            return Response::error('Skill not found.');
        }

        try {
            $runId = app(SkillPlaygroundRunAction::class)->execute(
                skill: $skill,
                input: $validated['input'],
                models: $validated['models'],
                teamId: $teamId,
                userId: auth()->id() ?? $skill->created_by,
            );

            return Response::text(json_encode([
                'run_id' => $runId,
                'models' => $validated['models'],
                'cache_ttl_seconds' => 300,
                'cache_key_pattern' => "skill_playground:{$teamId}:{$runId}:{modelId}",
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
