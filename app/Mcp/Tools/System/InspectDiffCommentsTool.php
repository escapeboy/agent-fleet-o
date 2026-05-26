<?php

namespace App\Mcp\Tools\System;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\InspectDiffCommentsAction;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
// @mcp-cross-tenant team-self-lookup — loads the caller's own team via mcp.team_id / current_team_id
class InspectDiffCommentsTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'code_inspect_comments';

    protected string $description = 'Review the comments a code change ADDS for comment discipline. Pass the unified diff (the output of `git diff`). Returns comments that are low-value (restate the code, decorative, commented-out, obvious) and should be removed or rewritten to explain a non-obvious WHY before opening a pull request. Advisory only.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'diff' => $schema->string()
                ->description('Unified diff to inspect (e.g. the output of `git diff` or `git diff <base>..<head>`).')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'diff' => 'required|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $team = Team::withoutGlobalScopes()->find($teamId);

        $result = app(InspectDiffCommentsAction::class)->execute(
            diff: $validated['diff'],
            team: $team,
            userId: auth()->id(),
        );

        return Response::text(json_encode($result->toArray()));
    }
}
