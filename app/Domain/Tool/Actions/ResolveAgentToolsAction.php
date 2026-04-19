<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Enums\AgentEnvironment;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\SandboxedWorkspace;
use App\Domain\Credential\Actions\ResolveProjectCredentialsAction;
use App\Domain\Credential\Models\Credential;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Tools\GitRepositoryToolBuilder;
use App\Domain\Project\Enums\ProjectExecutionMode;
use App\Domain\Project\Models\Project;
use App\Domain\Tool\Enums\BuiltInToolKind;
use App\Domain\Tool\Enums\ToolApprovalMode;
use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\TeamToolActivation;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\ToolSearchLog;
use App\Domain\Tool\Services\SemanticToolSelector;
use App\Domain\Tool\Services\ToolFederationResolver;
use App\Domain\Tool\Services\ToolRagSelector;
use App\Domain\Tool\Services\ToolTranslator;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\SynchronousWorkflowExecutor;
use App\Infrastructure\Encryption\CredentialEncryption;
use App\Models\GlobalSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Schema\StringSchema;

class ResolveAgentToolsAction
{
    public function __construct(
        private readonly ToolTranslator $translator,
        private readonly GitRepositoryToolBuilder $gitToolBuilder,
        private readonly SemanticToolSelector $semanticSelector,
        private readonly ToolRagSelector $toolRagSelector,
        private readonly ResolveProjectCredentialsAction $resolveCredentials,
        private readonly ToolFederationResolver $federationResolver,
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

        // Auto-attach tools declared by the agent's environment preset.
        // Environment is a convenience dropdown: Coding → bash+filesystem, Browsing → browser+web_search, etc.
        // Tools are matched by slug within the agent's team and merged uniquely by ID.
        $agentTools = $this->mergeEnvironmentTools($agent, $agentTools);

        // Filter out tools with approval_mode = 'deny' on the pivot
        $agentTools = $agentTools->filter(function (Tool $tool) {
            $mode = $tool->pivot->approval_mode ?? null;

            if ($mode instanceof ToolApprovalMode) {
                return $mode !== ToolApprovalMode::Deny;
            }

            return ($mode ?? 'auto') !== ToolApprovalMode::Deny->value;
        });

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

        // Role-based tag filtering — narrow tool set when agent declares tool_tags in config.
        // A tool passes if it has no tags (unrestricted) or shares at least one tag with the agent.
        // Fallback: if filtering would leave fewer than 3 tools, skip and log a warning.
        $agentToolTags = $agent->config['tool_tags'] ?? [];
        if (! empty($agentToolTags)) {
            $filtered = $agentTools->filter(function (Tool $tool) use ($agentToolTags) {
                $toolTags = $tool->tags ?? [];

                return empty($toolTags) || count(array_intersect($agentToolTags, $toolTags)) > 0;
            });

            if ($filtered->count() >= 3) {
                $agentTools = $filtered;
            } else {
                Log::warning('ResolveAgentTools: tag filter produced fewer than 3 tools, falling back to unfiltered set.', [
                    'agent_id' => $agent->id,
                    'tool_tags' => $agentToolTags,
                    'filtered_count' => $filtered->count(),
                ]);
            }
        }

        // Tool federation: merge team-wide tool pool when enabled on the agent.
        // Federation is opt-in (use_tool_federation flag defaults to false).
        $federatedTools = $this->federationResolver->resolve($agent);
        if ($federatedTools->isNotEmpty()) {
            $agentTools = $agentTools->merge($federatedTools)->unique('id');
        }

        // Tool search: auto-discover team-wide tools via semantic match when enabled.
        // Opt-in via use_tool_search config flag; requires a semanticQuery to be provided.
        // Merges up to tool_search_top_k (default 5) new tools, deduplicated against existing.
        $agentTools = $this->mergeSearchedTools($agent, $agentTools, $semanticQuery, $executionId);

        // Filter by execution mode: watcher projects only get safe/read tools
        if ($project && $project->execution_mode === ProjectExecutionMode::Watcher) {
            $agentTools = $agentTools->filter(
                fn (Tool $tool) => $tool->risk_level === null
                    || $tool->risk_level === ToolRiskLevel::Safe
                    || $tool->risk_level === ToolRiskLevel::Read,
            );
        }

        // RAG-style pre-filter: narrow the Tool model collection before expensive translation.
        // Runs keyword match → fuzzy name match → semantic pgvector (stages 1-3).
        // Only kicks in when a semantic query is provided and tool count exceeds the threshold.
        $threshold = SemanticToolSelector::threshold();
        if ($semanticQuery !== null && $agentTools->count() > $threshold) {
            $agentTools = $this->toolRagSelector->select(
                $agentTools,
                $semanticQuery,
                3,
                $agent->team_id,
                $agentTools->pluck('id')->toArray(),
            );
        }

        // Read org-level command security policy from GlobalSettings
        $orgPolicy = GlobalSetting::get('org_security_policy', []) ?: null;

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

            // For bash built-in tools: inject all agent credentials as CRED_* env vars
            // so scripts can authenticate with external services without the LLM seeing secret values.
            if ($tool->isBuiltIn() && BuiltInToolKind::tryFrom($tool->transport_config['kind'] ?? 'bash') === BuiltInToolKind::Bash) {
                $credentialEnv = $this->resolveCredentials->resolveAsEnvMap($agent->id);
                if (! empty($credentialEnv)) {
                    $tool = clone $tool;
                    $tool->transport_config = array_merge($tool->transport_config ?? [], [
                        'env' => array_merge($tool->transport_config['env'] ?? [], $credentialEnv),
                    ]);
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

        // Apply tool profile filtering — restrict tools to groups defined in the agent's profile
        $profile = $agent->tool_profile;
        if ($profile) {
            $profileConfig = config("tool_profiles.profiles.{$profile}");
            if ($profileConfig && ($profileConfig['tool_groups'] ?? []) !== ['*']) {
                $allowedGroups = $profileConfig['tool_groups'];
                $prismTools = array_values(array_filter($prismTools, function ($tool) use ($allowedGroups) {
                    $toolName = method_exists($tool, 'name') ? $tool->name() : ($tool->name ?? '');
                    foreach ($allowedGroups as $group) {
                        if (str_starts_with($toolName, $group.'_')) {
                            return true;
                        }
                    }

                    return false;
                }));
                if ($profileConfig['max_tools'] ?? null) {
                    $prismTools = array_slice($prismTools, 0, $profileConfig['max_tools']);
                }
            }
        }

        return $prismTools;
    }

    /**
     * Look up tools matching the agent environment's declared slugs (team-scoped)
     * and merge them with the explicit agent tool set, deduplicated by tool ID.
     * A missing slug is a silent no-op — the team may not have the tool seeded yet.
     *
     * @param  Collection<int, Tool>  $existing
     * @return Collection<int, Tool>
     */
    private function mergeEnvironmentTools(Agent $agent, Collection $existing): Collection
    {
        $environment = $agent->environment;
        if (! $environment instanceof AgentEnvironment) {
            return $existing;
        }

        $slugs = $environment->toolSlugs();
        if (empty($slugs) || $agent->team_id === null) {
            return $existing;
        }

        $envTools = Tool::where('team_id', $agent->team_id)
            ->whereIn('slug', $slugs)
            ->where('status', ToolStatus::Active->value)
            ->get();

        if ($envTools->isEmpty()) {
            return $existing;
        }

        return $existing->merge($envTools)->unique('id')->values();
    }

    /**
     * Expand the tool pool by semantic match against team-wide tools.
     * Runs only when the agent opts in via config.use_tool_search AND a
     * semanticQuery is provided. Already-attached tools are excluded from
     * the candidate pool so we only surface new discoveries.
     *
     * @param  Collection<int, Tool>  $existing
     * @return Collection<int, Tool>
     */
    private function mergeSearchedTools(Agent $agent, Collection $existing, ?string $semanticQuery, ?string $experimentId = null): Collection
    {
        if (! ($agent->config['use_tool_search'] ?? false)) {
            return $existing;
        }

        if ($semanticQuery === null || trim($semanticQuery) === '' || $agent->team_id === null) {
            return $existing;
        }

        $topK = max(1, min(20, (int) ($agent->config['tool_search_top_k'] ?? 5)));

        $existingIds = $existing->pluck('id')->all();

        $candidatePool = Tool::where('team_id', $agent->team_id)
            ->where('status', ToolStatus::Active->value)
            ->when(! empty($existingIds), fn ($q) => $q->whereNotIn('id', $existingIds))
            ->get();

        if ($candidatePool->isEmpty()) {
            return $existing;
        }

        try {
            $matched = $this->toolRagSelector->select(
                $candidatePool,
                $semanticQuery,
                3,
                $agent->team_id,
                $candidatePool->pluck('id')->toArray(),
            )->take($topK);
        } catch (\Throwable $e) {
            Log::warning('ResolveAgentTools: tool search failed, skipping', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return $existing;
        }

        if ($matched->isEmpty()) {
            return $existing;
        }

        Log::info('ResolveAgentTools: tool search discovered additional tools', [
            'agent_id' => $agent->id,
            'query_length' => mb_strlen($semanticQuery),
            'candidate_pool' => $candidatePool->count(),
            'matched' => $matched->pluck('slug')->all(),
        ]);

        $this->logToolSearch($agent, $semanticQuery, $candidatePool->count(), $matched, $experimentId);

        return $existing->merge($matched)->unique('id')->values();
    }

    /**
     * Persist an append-only audit log of the tool search selection.
     * Failure is non-fatal — the selection itself already succeeded.
     *
     * @param  Collection<int, Tool>  $matched
     */
    private function logToolSearch(Agent $agent, string $query, int $poolSize, Collection $matched, ?string $experimentId): void
    {
        try {
            ToolSearchLog::create([
                'team_id' => $agent->team_id,
                'agent_id' => $agent->id,
                'experiment_id' => $experimentId,
                'query' => mb_substr($query, 0, 2000),
                'pool_size' => $poolSize,
                'matched_count' => $matched->count(),
                'matched_slugs' => $matched->pluck('slug')->filter()->values()->all(),
                'matched_ids' => $matched->pluck('id')->values()->all(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ResolveAgentTools: failed to persist tool search log', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }
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
            $credential = Credential::withoutGlobalScopes()
                ->where('team_id', $tool->team_id)
                ->find($tool->credential_id);

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
