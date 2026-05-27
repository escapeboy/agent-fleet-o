<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Services\SkillQualityLinter;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Authoring-time skill lint (ZooEval failure-mode taxonomy): flags phantom tooling,
 * reference bloat, empty guidance, and missing output schema. Advisory; never blocks.
 */
#[IsReadOnly]
#[IsIdempotent]
class SkillLintTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'skill_lint';

    protected string $description = 'Lint a skill for ZooEval failure modes (phantom tooling, reference bloat, empty guidance, missing output schema). Read-only and advisory.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()
                ->description('The skill UUID')
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
            return $this->notFoundError('skill');
        }

        $findings = app(SkillQualityLinter::class)->lint($skill);

        return Response::text(json_encode([
            'skill_id' => $skill->id,
            'finding_count' => count($findings),
            'findings' => array_map(fn ($f) => $f->toArray(), $findings),
        ]));
    }
}
