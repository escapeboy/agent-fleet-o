<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Agent\Models\SandboxFileActivity;
use App\Domain\Experiment\Models\Experiment;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ExperimentSandboxFilesTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'experiment_sandbox_files';

    protected string $description = 'List files agents produced in their execution sandboxes for an experiment, newest first.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum files to return (default: 50, max: 200)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $validated = $request->validate([
            'experiment_id' => 'required|string',
            'limit' => 'integer|min:1|max:200',
        ]);

        $experiment = Experiment::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['experiment_id']);

        if (! $experiment) {
            return $this->notFoundError('experiment');
        }

        $files = SandboxFileActivity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('experiment_id', $experiment->id)
            ->orderByDesc('captured_at')
            ->limit($validated['limit'] ?? 50)
            ->get(['id', 'agent_id', 'sandbox_id', 'path', 'operation', 'size_bytes', 'captured_at']);

        return Response::text(json_encode([
            'experiment_id' => $experiment->id,
            'count' => $files->count(),
            'files' => $files->map(fn ($f) => [
                'id' => $f->id,
                'agent_id' => $f->agent_id,
                'sandbox_id' => $f->sandbox_id,
                'path' => $f->path,
                'operation' => $f->operation,
                'size_bytes' => $f->size_bytes,
                'captured_at' => $f->captured_at->toIso8601String(),
            ])->values()->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
