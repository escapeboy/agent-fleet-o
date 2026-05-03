<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\TestRatchetGuard;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class RepoTestRatchetCheckTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'repo_test_ratchet_check';

    protected string $description = 'Inspect a proposed change set for test-deletion or assertion-removal patterns (anti-cheat). Returns a verdict { violation, deleted_test_files, modified_test_files, removed_assertion_count, reason } without applying the change. Pure read tool — useful for previewing what GatedGitClient would block.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repo_id' => $schema->string()
                ->description('GitRepository UUID')
                ->required(),
            'changes' => $schema->array()
                ->description('Array of change descriptors, each: {path: string, mode?: "add"|"modify"|"delete", content?: string, content_before?: string}')
                ->items($schema->object()->description('Change descriptor with path, mode, content, content_before'))
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'repo_id' => 'required|string',
            'changes' => 'required|array',
            'changes.*.path' => 'required|string',
            'changes.*.mode' => 'nullable|string|in:add,modify,delete',
            'changes.*.content' => 'nullable|string',
            'changes.*.content_before' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $repo = GitRepository::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['repo_id']);
        if (! $repo) {
            return $this->notFoundError('git_repository');
        }

        /** @var list<array{path: string, mode?: string, content?: string|null, content_before?: string|null}> $changes */
        $changes = $validated['changes'];
        $verdict = app(TestRatchetGuard::class)->inspect($changes);

        return Response::json([
            'repo_id' => $repo->id,
            'mode' => $repo->config['test_ratchet_mode'] ?? 'soft',
            'verdict' => $verdict->toArray(),
        ]);
    }
}
