<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\SandboxedWorkspace;
use App\Domain\Credential\Models\Credential;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Tools\GitRepositoryToolBuilder;
use App\Domain\Project\Enums\ProjectExecutionMode;
use App\Domain\Project\Models\Project;
use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\TeamToolActivation;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\SemanticToolSelector;
use App\Domain\Tool\Services\ToolTranslator;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\SynchronousWorkflowExecutor;
use App\Infrastructure\Encryption\CredentialEncryption;
use App\Livewire\Settings\SecurityPolicyPanel;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Schema\StringSchema;

class ResolveAgentToolsAction
{
    public function __construct(
        private readonly ToolTranslator $translator,
        private readonly GitRepositoryToolBuilder $gitToolBuilder,
        private readonly SemanticToolSelector $semanticSelector,
    ) {}

    /**
     * Resolve all PrismPHP Tool objects available for an agent execution.
     *
     * @param  int  $agentToolDepth  Current nesting depth for agent-as-tool calls
     * @param  string[]|null  $allowedToolIds  Crew-member-level tool allowlist (BroodMind worker permissions).
     *                                         When non-empty, only tools whose IDs are in this list are included,
     *                                         after project-level restrictions have been applied.
     * @return array<\Prism\Prism\Tool>
     */
    public function execute(Agent $agent, ?Project $project = null, ?string $executionId = null, ?string $sidecarSessionId = null, int $agentToolDepth = 0, ?string $userId = null, ?string $semanticQuery = null, ?array $allowedToolIds = null): array
    {
        $workspace = ($executionId && $agent->team_id)
            ? new SandboxedWorkspace($executionId, $agent->id, $agent->team_id)
            : null;

        if ($workspace && $sidecarSessionId !== null) {
            $workspace->setSidecarSessionId($sidecarSessionId);
        }

        $agentTools = $agent->tools()
            ->where('status', ToolStatus::Active->value)
            ->get();

        // Apply project-level restrictions if set
        if ($project && ! empty($project->allowed_tool_ids)) {
            $agentTools = $agentTools->filter(
                fn (Tool $tool) => in_array($tool->id, $project->allowed_tool_ids),
            );
        }

        // Apply crew-member-level restrictions (BroodMind worker permission template).
        // null = no restriction (all tools allowed); [] = zero tools permitted.
        if ($allowedToolIds !== null) {
            $agentTools = $agentTools->filter(
                fn (Tool $tool) => in_array($tool->id, $allowedToolIds),
            );
        }

        // Filter by execution mode: watcher projects only get safe/read tools
        if ($project && $project->execution_mode === ProjectExecutionMode::Watcher) {
            $agentTools = $agentTools->filter(
                fn (Tool $tool) => $tool->risk_level === null
                    || $tool->risk_level === ToolRiskLevel::Safe
                    || $tool->risk_level === ToolRiskLevel::Read,
            );
        }

        // Read org-level command security policy from GlobalSettings
        $orgPolicy = SecurityPolicyPanel::getOrgPolicy() ?: null;

        // Pre-load activations for platform tools to avoid N+1
        $teamId = $agent->team_id;
        $platformToolIds = $agentTools->filter(fn (Tool $t) => $t->isPlatformTool())->pluck('id');
        $activations = $platformToolIds->isNotEmpty()
            ? TeamToolActivation::where('team_id', $teamId)
                ->whereIn('tool_id', $platformToolIds)
                ->get()
                ->keyBy('tool_id')
            : collect();

        $prismTools = [];
        foreach ($agentTools as $tool) {
            $overrides = $tool->pivot->overrides ?? [];

            // For platform tools: inject team-specific credentials into transport_config env vars
            if ($tool->isPlatformTool()) {
                $activation = $activations->get($tool->id);

                // Skip platform tools that are deactivated for this team
                if ($activation && ! $activation->isActive()) {
                    continue;
                }

                // Merge team credential overrides into the tool's transport_config env vars
                if ($activation && ! empty($activation->credential_overrides)) {
                    $tool = clone $tool;
                    $config = $tool->transport_config ?? [];
                    $config['env'] = array_merge(
                        $config['env'] ?? [],
                        $activation->credential_overrides,
                    );
                    $tool->transport_config = $config;
                }
            } else {
                // For team-owned tools: inject credentials from credential_id or inline credentials
                $resolvedSecret = $this->resolveToolCredential($tool);

                if ($resolvedSecret !== null) {
                    $tool = clone $tool;
                    $config = $tool->transport_config ?? [];
                    $envKey = $config['credential_env_var'] ?? 'API_KEY';
                    $secretValue = $resolvedSecret['api_key']
                        ?? $resolvedSecret['token']
                        ?? $resolvedSecret['password']
                        ?? $resolvedSecret['access_token']
                        ?? '';

                    if ($secretValue !== '') {
                        $config['env'] = array_merge($config['env'] ?? [], [$envKey => $secretValue]);
                        $tool->transport_config = $config;
                    }
                }
            }

            $prismTools = array_merge($prismTools, $this->translator->toPrismTools($tool, $overrides, $orgPolicy, $workspace));
        }

        // Inject git tools for repositories configured on the agent
        $gitRepoIds = $agent->config['git_repository_ids'] ?? [];
        if (! empty($gitRepoIds)) {
            $repos = GitRepository::where('team_id', $agent->team_id)
                ->whereIn('id', $gitRepoIds)
                ->where('status', 'active')
                ->get();

            foreach ($repos as $repo) {
                $prismTools = array_merge($prismTools, $this->gitToolBuilder->build($repo));
            }
        }

        // Inject agent-as-tool wrappers for callable agents (if within depth limit)
        $prismTools = array_merge($prismTools, $this->buildAgentAsTools($agent, $agentToolDepth, $userId));

        // Inject workflow-as-tool wrappers for callable workflows (if within depth limit)
        $prismTools = array_merge($prismTools, $this->buildWorkflowAsTools($agent, $agentToolDepth, $userId));

        // Semantic pre-filtering: if tool count exceeds threshold, filter by relevance to query
        $prismTools = $this->applySemanticFilter($prismTools, $agent, $semanticQuery);

        return $prismTools;
    }

    /**
     * Apply semantic pre-filtering when tool count exceeds threshold.
     *
     * Uses pgvector cosine similarity to select the most relevant tools
     * for the given query. Falls back to returning all tools when:
     * - No query provided
     * - Tool count below threshold
     * - pgvector unavailable (SQLite tests)
     * - Embedding search fails
     * - Coverage below 80% of threshold (too few matches)
     *
     * @param  array<\Prism\Prism\Tool>  $prismTools
     * @return array<\Prism\Prism\Tool>
     */
    private function applySemanticFilter(array $prismTools, Agent $agent, ?string $semanticQuery): array
    {
        $threshold = SemanticToolSelector::threshold();

        if ($semanticQuery === null || count($prismTools) <= $threshold) {
            return $prismTools;
        }

        // Collect tool model IDs that contributed to this agent's tools
        $toolIds = $agent->tools()
            ->pluck('tools.id')
            ->toArray();

        if (empty($toolIds)) {
            return $prismTools;
        }

        $limit = (int) config('tools.semantic_filter_limit', 12);
        $similarityThreshold = (float) config('tools.semantic_filter_similarity', 0.75);

        $matchingNames = $this->semanticSelector->searchToolNames(
            $semanticQuery,
            $agent->team_id,
            $toolIds,
            $limit,
            $similarityThreshold,
        );

        // If too few matches, return all tools (fallback)
        $minCoverage = (int) ceil($threshold * 0.8);
        if ($matchingNames->isEmpty() || $matchingNames->count() < $minCoverage) {
            Log::debug('SemanticToolSelector: insufficient matches, returning all tools', [
                'agent_id' => $agent->id,
                'total_tools' => count($prismTools),
                'matches' => $matchingNames->count(),
                'min_coverage' => $minCoverage,
            ]);

            return $prismTools;
        }

        $nameSet = $matchingNames->flip();

        $filtered = array_filter($prismTools, fn ($tool) => isset($nameSet[$tool->name()]));

        Log::debug('SemanticToolSelector: filtered tools', [
            'agent_id' => $agent->id,
            'total_tools' => count($prismTools),
            'filtered_to' => count($filtered),
            'query_preview' => substr($semanticQuery, 0, 100),
        ]);

        return array_values($filtered);
    }

    /**
     * Resolve the secret data for a team-owned tool.
     * Priority: linked Credential (credential_id) > inline Tool.credentials.
     *
     * @return array<string, string>|null
     */
    private function resolveToolCredential(Tool $tool): ?array
    {
        // Option 1: linked Credential domain record
        if ($tool->credential_id) {
            $credential = Credential::find($tool->credential_id);

            if ($credential && $credential->isUsable()) {
                CredentialEncryption::logAccess(
                    teamId: $tool->team_id,
                    subjectType: 'credential',
                    subjectId: $credential->id,
                    purpose: 'tool_execution',
                    extra: ['tool_id' => $tool->id, 'tool_name' => $tool->name],
                );

                $credential->touchLastUsed();

                return $credential->secret_data;
            }
        }

        // Option 2: inline credentials on the tool (already decrypted by TeamEncryptedArray cast)
        if (! empty($tool->credentials)) {
            return $tool->credentials;
        }

        return null;
    }

    /**
     * Build PrismPHP Tool wrappers for agents configured as callable tools.
     * Each callable agent becomes an LLM tool that delegates to ExecuteAgentAction.
     *
     * @return array<\Prism\Prism\Tool>
     */
    private function buildAgentAsTools(Agent $parentAgent, int $currentDepth, ?string $userId = null): array
    {
        $callableIds = $parentAgent->config['callable_agent_ids'] ?? [];
        if (empty($callableIds)) {
            return [];
        }

        // Don't inject agent tools if we'd exceed the depth limit on the next call
        $maxDepth = config('agent.max_agent_tool_depth', 3);
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $callableAgents = Agent::where('team_id', $parentAgent->team_id)
            ->whereIn('id', $callableIds)
            ->where('status', AgentStatus::Active)
            ->get();

        $tools = [];
        foreach ($callableAgents as $callableAgent) {
            $agentId = $callableAgent->id;
            $agentName = $callableAgent->name;
            $teamId = $parentAgent->team_id;
            $nextDepth = $currentDepth + 1;
            $callerUserId = $userId ?? 'system';

            $description = "Delegate a task to agent \"{$agentName}\"";
            if ($callableAgent->role) {
                $description .= " ({$callableAgent->role})";
            }
            if ($callableAgent->goal) {
                $description .= ". Goal: {$callableAgent->goal}";
            }

            $toolName = 'call_agent_'.preg_replace('/[^a-z0-9_]/', '_', strtolower($agentName));
            // Ensure unique tool name by appending short ID suffix
            $toolName = substr($toolName, 0, 50).'_'.substr($agentId, 0, 8);

            $tools[] = PrismTool::as($toolName)
                ->for($description)
                ->withParameter(new StringSchema('task', 'The task or question to delegate to this agent'))
                ->withParameter(new StringSchema('context', 'Additional context from the current conversation (optional)'))
                ->using(function (string $task, string $context = '') use ($agentId, $teamId, $nextDepth, $callerUserId): string {
                    try {
                        $agent = Agent::withoutGlobalScopes()->find($agentId);
                        // Defense-in-depth: verify team_id even though buildAgentAsTools filters by team
                        if (! $agent || $agent->team_id !== $teamId || $agent->status !== AgentStatus::Active) {
                            return json_encode(['error' => 'Agent is not available']);
                        }

                        $executeAction = app(ExecuteAgentAction::class);
                        $result = $executeAction->execute(
                            agent: $agent,
                            input: [
                                'task' => $task,
                                'context' => $context !== '' ? $context : null,
                                '_is_nested_call' => true,
                                '_agent_tool_depth' => $nextDepth,
                            ],
                            teamId: $teamId,
                            userId: $callerUserId,
                        );

                        if ($result['output'] === null) {
                            return json_encode([
                                'error' => $result['execution']->error_message ?? 'Agent execution failed',
                            ]);
                        }

                        $output = $result['output']['result'] ?? $result['output'];

                        return is_string($output) ? $output : json_encode($output);
                    } catch (\Throwable $e) {
                        Log::warning('Agent-as-tool execution failed', [
                            'parent_agent_id' => $parentAgent->id,
                            'callable_agent_id' => $agentId,
                            'error' => $e->getMessage(),
                        ]);

                        return json_encode(['error' => 'Agent execution failed']);
                    }
                });
        }

        return $tools;
    }

    /**
     * Build PrismPHP Tool wrappers for workflows configured as callable tools.
     * Each callable workflow becomes an LLM tool that delegates to SynchronousWorkflowExecutor.
     *
     * @return array<\Prism\Prism\Tool>
     */
    private function buildWorkflowAsTools(Agent $parentAgent, int $currentDepth, ?string $userId = null): array
    {
        $callableIds = $parentAgent->config['callable_workflow_ids'] ?? [];
        if (empty($callableIds)) {
            return [];
        }

        // Don't inject workflow tools if we'd exceed the depth limit on the next call
        $maxDepth = min(
            (int) config('workflows.max_recursion_depth', 5),
            (int) config('agent.max_agent_tool_depth', 3),
        );
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $callableWorkflows = Workflow::where('team_id', $parentAgent->team_id)
            ->whereIn('id', $callableIds)
            ->where('status', WorkflowStatus::Active)
            ->get();

        $tools = [];
        foreach ($callableWorkflows as $workflow) {
            // Reject workflows with human_task nodes (async-only)
            $hasHumanTask = $workflow->nodes()
                ->where('type', WorkflowNodeType::HumanTask->value)
                ->exists();

            if ($hasHumanTask) {
                Log::debug('Skipping workflow-as-tool: contains human_task nodes', [
                    'workflow_id' => $workflow->id,
                    'workflow_name' => $workflow->name,
                ]);

                continue;
            }

            $workflowId = $workflow->id;
            $workflowName = $workflow->name;
            $teamId = $parentAgent->team_id;
            $nextDepth = $currentDepth + 1;
            $callerUserId = $userId ?? 'system';

            $description = "Execute workflow \"{$workflowName}\"";
            if ($workflow->description) {
                $description .= '. '.$workflow->description;
            }

            $toolName = 'run_workflow_'.preg_replace('/[^a-z0-9_]/', '_', strtolower($workflowName));
            $toolName = substr($toolName, 0, 50).'_'.substr($workflowId, 0, 8);

            $tools[] = PrismTool::as($toolName)
                ->for($description)
                ->withParameter(new StringSchema('goal', 'The goal or task for this workflow to accomplish'))
                ->withParameter(new StringSchema('context', 'Additional context for the workflow (optional)'))
                ->using(function (string $goal, string $context = '') use ($workflowId, $teamId, $nextDepth, $callerUserId): string {
                    try {
                        $workflow = Workflow::withoutGlobalScopes()->find($workflowId);
                        if (! $workflow || $workflow->team_id !== $teamId || $workflow->status !== WorkflowStatus::Active) {
                            return json_encode(['error' => 'Workflow is not available']);
                        }

                        $executor = app(SynchronousWorkflowExecutor::class);

                        return $executor->execute(
                            workflow: $workflow,
                            teamId: $teamId,
                            userId: $callerUserId,
                            input: [
                                'goal' => $goal,
                                'context' => $context !== '' ? $context : null,
                            ],
                            currentDepth: $nextDepth,
                        );
                    } catch (\Throwable $e) {
                        Log::warning('Workflow-as-tool execution failed', [
                            'workflow_id' => $workflowId,
                            'error' => $e->getMessage(),
                        ]);

                        return json_encode(['error' => 'Workflow execution failed']);
                    }
                });
        }

        return $tools;
    }
}
