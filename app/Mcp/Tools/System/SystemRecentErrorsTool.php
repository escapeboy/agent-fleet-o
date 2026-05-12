<?php

declare(strict_types=1);

namespace App\Mcp\Tools\System;

use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Project\Models\ProjectRun;
use App\Infrastructure\Telemetry\Sentry\SentryUrlBuilder;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Aggregates recent platform failures across sub-program tables.
 *
 * Pulls the latest N rows whose `error_metadata` is non-null from:
 *   experiment_stages, playbook_steps, crew_executions, project_runs
 *
 * Returns a unified shape with sentry_event_id, captured_at, error_class,
 * error_message and a deep_link composed via SentryUrlBuilder.
 *
 * Multi-tenant: scoped to the caller's team_id resolved from auth context.
 * Cross-tenant leakage is prevented because every source query filters by
 * team_id explicitly and uses withoutGlobalScopes() to avoid the platform
 * row passthrough on the TeamScope.
 */
#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class SystemRecentErrorsTool extends Tool
{
    protected string $name = 'system_recent_errors';

    protected string $description = 'List recent platform failures across experiment stages, playbook steps, crew executions, and project runs. Each row includes the Sentry event id, error class, error message, captured_at, and a deep link to the Sentry issue (when SENTRY_ORG_SLUG is configured). Scoped to the current team.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max rows to return across all sub-programs (default 25, max 200).')
                ->default(25),
            'sub_program' => $schema->string()
                ->description('Optional filter: one of experiment.stage | crew.task | project.run | workflow.node. Omit for all.'),
        ];
    }

    public function handle(Request $request, SentryUrlBuilder $urlBuilder): Response
    {
        $teamId = $this->resolveTeamId();
        if ($teamId === null) {
            return Response::error('No team context available.');
        }

        $limit = max(1, min(200, (int) $request->get('limit', 25)));
        $filter = $request->get('sub_program');

        $rows = collect();

        if ($filter === null || $filter === 'experiment.stage') {
            $rows = $rows->merge($this->collectStages($teamId, $limit));
        }
        if ($filter === null || $filter === 'workflow.node') {
            $rows = $rows->merge($this->collectPlaybookSteps($teamId, $limit));
        }
        if ($filter === null || $filter === 'crew.task') {
            $rows = $rows->merge($this->collectCrewExecutions($teamId, $limit));
        }
        if ($filter === null || $filter === 'project.run') {
            $rows = $rows->merge($this->collectProjectRuns($teamId, $limit));
        }

        $payload = $rows
            ->sortByDesc(fn (array $row) => $row['captured_at'] ?? '')
            ->take($limit)
            ->map(function (array $row) use ($urlBuilder): array {
                $row['sentry_url'] = $urlBuilder->fromMetadata($row['error_metadata']);
                // Don't leak the full metadata payload to MCP clients — keep MCP responses small.
                unset($row['error_metadata']);

                return $row;
            })
            ->values()
            ->all();

        return Response::text(json_encode([
            'count' => count($payload),
            'errors' => $payload,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Auth context can resolve the team via the MCP server's auth bootstrap.
     * On stdio the bootstrap binds `mcp.active` and `auth()->user()->currentTeam`.
     * On HTTP/SSE the Sanctum token's user is set.
     */
    private function resolveTeamId(): ?string
    {
        $teamId = auth()->user()?->current_team_id;

        return $teamId === null ? null : (string) $teamId;
    }

    private function collectStages(string $teamId, int $limit): Collection
    {
        return ExperimentStage::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNotNull('error_metadata')
            ->latest('updated_at')
            ->limit($limit)
            ->get(['id', 'experiment_id', 'stage', 'error_metadata', 'updated_at'])
            ->map(fn (ExperimentStage $stage) => $this->normalise(
                subProgram: 'experiment.stage',
                id: $stage->id,
                relatedId: $stage->experiment_id,
                metadata: is_array($stage->error_metadata) ? $stage->error_metadata : [],
                fallbackCapturedAt: $stage->updated_at?->toIso8601String() ?? '',
            ));
    }

    private function collectPlaybookSteps(string $teamId, int $limit): Collection
    {
        return PlaybookStep::withoutGlobalScopes()
            ->join('experiments', 'experiments.id', '=', 'playbook_steps.experiment_id')
            ->where('experiments.team_id', $teamId)
            ->whereNotNull('playbook_steps.error_metadata')
            ->latest('playbook_steps.updated_at')
            ->limit($limit)
            ->select(['playbook_steps.id', 'playbook_steps.experiment_id', 'playbook_steps.workflow_node_id', 'playbook_steps.error_metadata', 'playbook_steps.updated_at'])
            ->get()
            ->map(fn (PlaybookStep $step) => $this->normalise(
                subProgram: $step->workflow_node_id ? 'workflow.node' : 'experiment.stage',
                id: $step->id,
                relatedId: $step->experiment_id,
                metadata: is_array($step->error_metadata) ? $step->error_metadata : [],
                fallbackCapturedAt: $step->updated_at?->toIso8601String() ?? '',
            ));
    }

    private function collectCrewExecutions(string $teamId, int $limit): Collection
    {
        return CrewExecution::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNotNull('error_metadata')
            ->latest('updated_at')
            ->limit($limit)
            ->get(['id', 'crew_id', 'error_metadata', 'updated_at'])
            ->map(fn (CrewExecution $exec) => $this->normalise(
                subProgram: 'crew.task',
                id: $exec->id,
                relatedId: $exec->crew_id,
                metadata: is_array($exec->error_metadata) ? $exec->error_metadata : [],
                fallbackCapturedAt: $exec->updated_at?->toIso8601String() ?? '',
            ));
    }

    private function collectProjectRuns(string $teamId, int $limit): Collection
    {
        return ProjectRun::join('projects', 'projects.id', '=', 'project_runs.project_id')
            ->where('projects.team_id', $teamId)
            ->whereNotNull('project_runs.error_metadata')
            ->latest('project_runs.updated_at')
            ->limit($limit)
            ->select(['project_runs.id', 'project_runs.project_id', 'project_runs.error_metadata', 'project_runs.updated_at'])
            ->get()
            ->map(fn (ProjectRun $run) => $this->normalise(
                subProgram: 'project.run',
                id: $run->id,
                relatedId: $run->project_id,
                metadata: is_array($run->error_metadata) ? $run->error_metadata : [],
                fallbackCapturedAt: $run->updated_at?->toIso8601String() ?? '',
            ));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function normalise(
        string $subProgram,
        string $id,
        ?string $relatedId,
        array $metadata,
        string $fallbackCapturedAt,
    ): array {
        return [
            'sub_program' => $subProgram,
            'id' => $id,
            'related_id' => $relatedId,
            'sentry_event_id' => $metadata['sentry_event_id'] ?? null,
            'error_class' => $metadata['error_class'] ?? null,
            'error_message' => $metadata['error_message'] ?? null,
            'captured_at' => $metadata['captured_at'] ?? $fallbackCapturedAt,
            'fingerprint' => $metadata['fingerprint'] ?? [],
            'error_metadata' => $metadata, // included only for SentryUrlBuilder; stripped before return
        ];
    }
}
