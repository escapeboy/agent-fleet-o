<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillVersion;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SkillVersionsTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'skill_versions';

    protected string $description = 'List all versions of a skill ordered by most recent. Returns version number, changelog, and created_at.';

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

        $versions = $skill->versions()->orderByDesc('version')->get();

        return Response::text(json_encode([
            'skill_id' => $skill->id,
            'skill_name' => $skill->name,
            'count' => $versions->count(),
            'versions' => $versions->map(function (Model $v) {
                /** @var SkillVersion $v */
                return [
                    'id' => $v->id,
                    'version' => $v->version,
                    'changelog' => $v->changelog,
                    'created_by' => $v->created_by,
                    'created_at' => $v->created_at->toIso8601String(),
                ];
            })->toArray(),
        ]));
    }
}
