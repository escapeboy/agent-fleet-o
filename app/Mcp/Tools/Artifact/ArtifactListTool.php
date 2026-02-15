<?php

namespace App\Mcp\Tools\Artifact;

use App\Domain\Experiment\Services\ArtifactContentResolver;
use App\Models\Artifact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ArtifactListTool extends Tool
{
    protected string $name = 'artifact_list';

    protected string $description = 'List artifacts, optionally filtered by experiment, crew execution, or project run. Returns artifact metadata with preview URLs.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('Filter by experiment UUID'),
            'crew_execution_id' => $schema->string()
                ->description('Filter by crew execution UUID'),
            'project_run_id' => $schema->string()
                ->description('Filter by project run UUID'),
            'limit' => $schema->integer()
                ->description('Max results (default 20)')
                ->minimum(1)
                ->maximum(100),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'experiment_id' => 'sometimes|string',
            'crew_execution_id' => 'sometimes|string',
            'project_run_id' => 'sometimes|string',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = Artifact::query()
            ->withCount('versions')
            ->orderByDesc('created_at');

        if (! empty($validated['experiment_id'])) {
            $query->where('experiment_id', $validated['experiment_id']);
        }
        if (! empty($validated['crew_execution_id'])) {
            $query->where('crew_execution_id', $validated['crew_execution_id']);
        }
        if (! empty($validated['project_run_id'])) {
            $query->where('project_run_id', $validated['project_run_id']);
        }

        $artifacts = $query->limit($validated['limit'] ?? 20)->get();

        $result = $artifacts->map(fn (Artifact $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'type' => $a->type,
            'category' => ArtifactContentResolver::category($a->type),
            'current_version' => $a->current_version,
            'versions_count' => $a->versions_count,
            'experiment_id' => $a->experiment_id,
            'crew_execution_id' => $a->crew_execution_id,
            'project_run_id' => $a->project_run_id,
            'preview_url' => route('artifacts.render', $a->id),
            'created_at' => $a->created_at?->toIso8601String(),
        ]);

        return Response::text(json_encode([
            'count' => $result->count(),
            'artifacts' => $result->values(),
        ]));
    }
}
