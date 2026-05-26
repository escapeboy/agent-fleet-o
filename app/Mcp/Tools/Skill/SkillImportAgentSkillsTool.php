<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Actions\ImportSkillFromAgentSkillsAction;
use App\Domain\Skill\Support\SkillKitSpec;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SkillImportAgentSkillsTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'skill_import_agentskills';

    protected string $description = 'Create a skill from a portable agentskills.io SKILL.md document (--- YAML frontmatter --- then Markdown). FleetQ fields are restored from metadata.fleetq when present.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_md' => $schema->string()
                ->description('The full SKILL.md document text.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['skill_md' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        try {
            $skill = app(ImportSkillFromAgentSkillsAction::class)->execute(
                teamId: $teamId,
                skillMd: $validated['skill_md'],
                createdBy: auth()->id(),
            );
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentError($e->getMessage());
        }

        return Response::text(json_encode([
            'success' => true,
            'id' => $skill->id,
            'slug' => $skill->slug,
            'name' => $skill->name,
            'section_warnings' => SkillKitSpec::missingSections($validated['skill_md']),
        ]));
    }
}
