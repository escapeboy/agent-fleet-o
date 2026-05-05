<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Models\Skill;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class SkillCloneTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'skill_clone';

    protected string $description = 'Clone an existing skill with a new name.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()->description('The skill ID to clone.')->required(),
            'name' => $schema->string()->description('New name for the cloned skill.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $skill = Skill::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('skill_id'));
        if (! $skill) {
            return $this->notFoundError('skill');
        }

        $clone = $skill->replicate();
        $clone->name = $request->get('name');
        $clone->status = SkillStatus::Draft;
        $clone->save();

        return Response::text(json_encode([
            'success' => true,
            'id' => $clone->id,
            'name' => $clone->name,
            'status' => $clone->status->value,
            'cloned_from' => $skill->id,
        ]));
    }
}
