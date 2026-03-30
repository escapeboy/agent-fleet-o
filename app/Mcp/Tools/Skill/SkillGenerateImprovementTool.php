<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Actions\GenerateImprovedSkillVersionAction;
use App\Domain\Skill\Exceptions\InsufficientAnnotationsException;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillVersion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool: skill_generate_improvement
 *
 * Reads existing annotations for a skill version, builds a meta-prompt from
 * good/bad examples, and produces an AI-improved SkillVersion.
 * Requires at least 1 good and 1 bad annotation.
 */
class SkillGenerateImprovementTool extends Tool
{
    protected string $name = 'skill_generate_improvement';

    protected string $description = 'Generate an improved skill version using annotations as few-shot examples. Requires at least 1 good and 1 bad annotation for the specified version.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()
                ->description('UUID of the skill to improve')
                ->required(),
            'version_id' => $schema->string()
                ->description('UUID of the SkillVersion whose annotations to use as training data')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'skill_id' => 'required|string',
            'version_id' => 'required|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $skill = Skill::find($validated['skill_id']);
        if (! $skill || $skill->team_id !== $teamId) {
            return Response::error('Skill not found.');
        }

        $version = SkillVersion::find($validated['version_id']);
        if (! $version || $version->skill_id !== $skill->id) {
            return Response::error('Skill version not found.');
        }

        try {
            $newVersion = app(GenerateImprovedSkillVersionAction::class)->execute(
                skill: $skill,
                version: $version,
                teamId: $teamId,
                userId: auth()->id() ?? $skill->created_by,
            );

            return Response::text(json_encode([
                'success' => true,
                'new_version_id' => $newVersion->id,
                'new_version' => $newVersion->version,
                'changelog' => $newVersion->changelog,
            ]));
        } catch (InsufficientAnnotationsException $e) {
            return Response::error($e->getMessage());
        } catch (\Throwable $e) {
            return Response::error('Improvement failed: '.$e->getMessage());
        }
    }
}
