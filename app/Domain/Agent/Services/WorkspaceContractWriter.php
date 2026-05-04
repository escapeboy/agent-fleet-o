<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\DTOs\WorkspaceContractSnapshot;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMilestone;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Builds and persists the AGENTS.md / feature-list.json / progress.md / init.sh
 * workspace contract that travels with every long-running agent invocation.
 *
 * Pattern borrowed from Anthropic's effective-harness post:
 *   - feature-list.json is the externalized done condition.
 *   - progress.md is the agent's rolling lab notebook.
 *   - AGENTS.md is the "rules of the road" the agent reads on every wake.
 *   - init.sh is the cold-boot script the sandbox runs once.
 *
 * The snapshot is written to disk if a SandboxedWorkspace is supplied, AND
 * always persisted to AgentExecution.workspace_contract so a future wake
 * can rehydrate the contract even after sandbox teardown.
 */
class WorkspaceContractWriter
{
    /**
     * Build, materialize (if sandbox supplied), and persist the contract.
     */
    public function prepare(
        AgentExecution $execution,
        ?SandboxedWorkspace $sandbox = null,
    ): WorkspaceContractSnapshot {
        $agent = Agent::withoutGlobalScopes()->find($execution->agent_id);
        $workflow = $this->resolveWorkflow($execution);
        $project = $this->resolveProject($execution);

        $snapshot = $this->buildSnapshot(
            agent: $agent,
            workflow: $workflow,
            project: $project,
            executionContext: [
                'execution_id' => $execution->id,
                'agent_id' => $execution->agent_id,
                'created_at' => $execution->created_at?->toIso8601String(),
                'input' => $execution->input,
            ],
        );

        if ($sandbox !== null) {
            $this->materializeSnapshot($sandbox, $snapshot);
        }

        $execution->update(['workspace_contract' => $snapshot->toArray()]);

        return $snapshot;
    }

    /**
     * Build a snapshot without persisting or materializing.
     *
     * Used by call sites that want the contract built upfront — before an
     * AgentExecution row exists — so it can be (a) materialized to a sandbox
     * for the LLM tool-loop to read, and (b) stored on the eventual
     * AgentExecution row when the run record is created.
     *
     * @param  array{execution_id?: string|null, agent_id?: string|null, created_at?: string|null, input?: mixed}  $executionContext
     */
    public function buildSnapshot(
        ?Agent $agent,
        ?Workflow $workflow,
        ?Project $project,
        array $executionContext = [],
    ): WorkspaceContractSnapshot {
        return new WorkspaceContractSnapshot(
            agentsMd: $this->buildAgentsMdFromContext($agent, $executionContext, $project),
            featureListJson: $this->buildFeatureListJsonFromContext($executionContext, $workflow, $project),
            progressMd: $this->buildProgressMdFromContext($executionContext),
            initSh: $this->buildInitSh($agent),
        );
    }

    /**
     * Write a snapshot's 4 files into a sandbox without touching the DB.
     */
    public function materializeSnapshot(SandboxedWorkspace $sandbox, WorkspaceContractSnapshot $snapshot): void
    {
        $this->materialize($sandbox, $snapshot);
    }

    /**
     * Convenience for ExecuteAgentAction: resolves the optional Workflow from
     * an experimentId and delegates to buildSnapshot(). Avoids leaking
     * resolveWorkflow/resolveProject internals into action code.
     *
     * @param  array<string, mixed>  $input
     */
    public function buildSnapshotForExecution(
        Agent $agent,
        ?string $experimentId,
        ?Project $project,
        array $input,
    ): WorkspaceContractSnapshot {
        $workflow = null;
        if (is_string($experimentId) && $experimentId !== '') {
            $experiment = Experiment::withoutGlobalScopes()->find($experimentId);
            $workflowId = $experiment?->workflow_id;
            if (is_string($workflowId) && $workflowId !== '') {
                $workflow = Workflow::withoutGlobalScopes()->find($workflowId);
            }
        }

        return $this->buildSnapshot(
            agent: $agent,
            workflow: $workflow,
            project: $project,
            executionContext: [
                'execution_id' => null,
                'agent_id' => $agent->id,
                'created_at' => null,
                'input' => $input,
            ],
        );
    }

    /**
     * Read the snapshot back from AgentExecution.workspace_contract,
     * defaulting to a freshly built one when absent.
     */
    public function restoreOrPrepare(AgentExecution $execution): WorkspaceContractSnapshot
    {
        $payload = $execution->workspace_contract;
        if (is_array($payload) && $payload !== []) {
            return WorkspaceContractSnapshot::fromArray($payload);
        }

        return $this->prepare($execution);
    }

    /**
     * Append a single timestamped iteration line to progress.md and
     * persist back to the snapshot. Idempotency is the caller's problem.
     */
    public function appendProgress(AgentExecution $execution, string $note, ?SandboxedWorkspace $sandbox = null): void
    {
        $snapshot = $this->restoreOrPrepare($execution);

        $line = '- ['.Carbon::now()->toIso8601String().'] '.trim($note);
        $newProgress = rtrim($snapshot->progressMd)."\n".$line."\n";

        $next = new WorkspaceContractSnapshot(
            agentsMd: $snapshot->agentsMd,
            featureListJson: $snapshot->featureListJson,
            progressMd: $newProgress,
            initSh: $snapshot->initSh,
        );

        if ($sandbox !== null) {
            $this->materialize($sandbox, $next);
        }

        $execution->update(['workspace_contract' => $next->toArray()]);
    }

    private function materialize(SandboxedWorkspace $sandbox, WorkspaceContractSnapshot $snapshot): void
    {
        // resolve() guards against path traversal; these literal names cannot escape.
        file_put_contents($sandbox->resolve('AGENTS.md'), $snapshot->agentsMd);
        file_put_contents($sandbox->resolve('feature-list.json'), $snapshot->featureListJson);
        file_put_contents($sandbox->resolve('progress.md'), $snapshot->progressMd);
        $initPath = $sandbox->resolve('init.sh');
        file_put_contents($initPath, $snapshot->initSh);
        @chmod($initPath, 0o755);
    }

    /**
     * @param  array{execution_id?: string|null, agent_id?: string|null, created_at?: string|null, input?: mixed}  $context
     */
    private function buildAgentsMdFromContext(?Agent $agent, array $context, ?Project $project): string
    {
        $name = $agent?->name ?? 'Agent';
        $role = $agent?->role ?? 'Generalist';
        $goal = $agent?->goal ?? '(no goal set)';

        $rules = [
            'Update progress.md after each meaningful step (a new tool call cluster, a test run, a deploy attempt).',
            'Do not delete or weaken tests to make a build green. Restore removed assertions before claiming completion.',
            'Re-read feature-list.json before declaring any feature done. Each feature has explicit done_criteria.',
            'When uncertain about scope, ask via a clarification step rather than guessing.',
        ];

        if ($project) {
            $rules[] = "This work belongs to project '{$project->title}' — favour solutions that compose with prior project runs.";
        }

        $rulesBlock = collect($rules)->map(fn ($r) => "- $r")->implode("\n");
        $executionId = (string) ($context['execution_id'] ?? '(pending)');
        $startedAt = (string) ($context['created_at'] ?? Carbon::now()->toIso8601String());

        return <<<MD
        # AGENTS.md

        Long-running workspace contract for **{$name}** ({$role}).

        ## Goal

        {$goal}

        ## Rules

        {$rulesBlock}

        ## Files in this workspace

        - `feature-list.json` — externalized features + done_criteria. The Done-Condition Judge reads this to verify completion.
        - `progress.md` — rolling iteration log. Append using the `progress_append` built-in tool.
        - `init.sh` — cold-boot script run once when a fresh sandbox is provisioned for this execution.

        ## Execution

        - id: {$executionId}
        - started_at: {$startedAt}

        MD;
    }

    /**
     * @param  array{execution_id?: string|null, agent_id?: string|null, input?: mixed}  $context
     */
    private function buildFeatureListJsonFromContext(array $context, ?Workflow $workflow, ?Project $project): string
    {
        $features = [];

        if ($workflow) {
            $nodes = $workflow->nodes()->orderBy('created_at')->get();
            foreach ($nodes as $node) {
                $config = is_array($node->config ?? null) ? $node->config : [];
                $features[] = [
                    'id' => (string) $node->id,
                    'title' => (string) ($node->name ?? $node->node_type ?? 'node'),
                    'done_criteria' => $config['done_criteria'] ?? null,
                    'status' => 'pending',
                ];
            }
        } elseif ($project) {
            $milestones = ProjectMilestone::query()
                ->where('project_id', $project->id)
                ->orderBy('sort_order')
                ->get();
            foreach ($milestones as $m) {
                $features[] = [
                    'id' => (string) $m->id,
                    'title' => (string) $m->title,
                    'done_criteria' => $m->success_criteria ?? null,
                    'status' => $m->status?->value ?? 'pending',
                ];
            }
        }

        if ($features === []) {
            $features[] = [
                'id' => Str::uuid7()->toString(),
                'title' => 'Primary execution goal',
                'done_criteria' => $context['input'] ?? null,
                'status' => 'pending',
            ];
        }

        return json_encode([
            'execution_id' => $context['execution_id'] ?? null,
            'agent_id' => $context['agent_id'] ?? null,
            'features' => $features,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array{execution_id?: string|null}  $context
     */
    private function buildProgressMdFromContext(array $context): string
    {
        $executionId = (string) ($context['execution_id'] ?? '(pending)');

        return <<<MD
        # progress.md

        Rolling iteration log for execution `{$executionId}`.

        ## Iteration log

        ## Blockers

        ## Decisions

        MD;
    }

    private function buildInitSh(?Agent $agent): string
    {
        $lines = [
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            '',
            'echo "[init] starting workspace for agent '.($agent?->id ?? 'unknown').'"',
            '',
            'if [ -f composer.json ]; then',
            '  composer install --no-interaction --prefer-dist 2>/dev/null || true',
            'fi',
            'if [ -f package.json ]; then',
            '  npm ci --no-audit --no-fund 2>/dev/null || true',
            'fi',
            '',
            'echo "[init] complete"',
        ];

        return implode("\n", $lines)."\n";
    }

    private function resolveWorkflow(AgentExecution $execution): ?Workflow
    {
        $experiment = $execution->experiment_id
            ? Experiment::withoutGlobalScopes()->find($execution->experiment_id)
            : null;
        $workflowId = $experiment?->workflow_id;
        if (! is_string($workflowId) || $workflowId === '') {
            return null;
        }

        return Workflow::withoutGlobalScopes()->find($workflowId);
    }

    private function resolveProject(AgentExecution $execution): ?Project
    {
        $experiment = $execution->experiment_id
            ? Experiment::withoutGlobalScopes()->find($execution->experiment_id)
            : null;
        $projectRunId = $experiment?->project_run_id ?? null;
        if (! is_string($projectRunId) || $projectRunId === '') {
            return null;
        }
        $projectRun = ProjectRun::withoutGlobalScopes()->find($projectRunId);

        return $projectRun ? Project::withoutGlobalScopes()->find($projectRun->project_id) : null;
    }
}
