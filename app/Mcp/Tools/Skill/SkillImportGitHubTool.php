<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Actions\ImportSkillFromGitHubAction;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SkillImportGitHubTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'skill_import_github';

    protected string $description = 'Install SkillKit skills from a GitHub repo path (like `npx skills add org/repo/skills`). Source: "org/repo[/path][@ref]". Imports a single SKILL.md or every SKILL.md under a directory (and its immediate subdirectories). Public repos need no token.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'source' => $schema->string()
                ->description('GitHub source as "org/repo[/path][@ref]", e.g. "iii-hq/iii/skills" or "octo/repo@v1".')
                ->required(),
            'token' => $schema->string()
                ->description('Optional GitHub token for private repos or higher rate limits.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'source' => 'required|string',
            'token' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        try {
            $result = app(ImportSkillFromGitHubAction::class)->execute(
                teamId: $teamId,
                source: $validated['source'],
                token: $validated['token'] ?? null,
                createdBy: auth()->id(),
            );
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentError($e->getMessage());
        }

        return Response::text(json_encode([
            'success' => true,
            'imported' => array_map(static fn ($skill) => [
                'id' => $skill->id,
                'slug' => $skill->slug,
                'name' => $skill->name,
            ], $result['imported']),
            'failed' => $result['failed'],
            'section_warnings' => $result['warnings'],
        ]));
    }
}
