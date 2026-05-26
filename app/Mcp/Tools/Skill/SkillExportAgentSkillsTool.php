<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Actions\ExportSkillToAgentSkillsAction;
use App\Domain\Skill\Models\Skill;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SkillExportAgentSkillsTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'skill_export_agentskills';

    protected string $description = 'Export a skill as a portable agentskills.io SKILL.md document (YAML frontmatter + Markdown instructions). FleetQ fields are preserved under metadata.fleetq for lossless re-import.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()
                ->description('The skill UUID to export.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['skill_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $skill = Skill::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['skill_id']);
        if (! $skill) {
            return $this->notFoundError('skill', $validated['skill_id']);
        }

        return Response::text(app(ExportSkillToAgentSkillsAction::class)->execute($skill));
    }
}
