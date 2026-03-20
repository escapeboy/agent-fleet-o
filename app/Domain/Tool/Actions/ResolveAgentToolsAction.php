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
use App\Domain\Tool\Services\ToolTranslator;
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
    ) {}

    /**
     * Resolve all PrismPHP Tool objects available for an agent execution.
     *
     * @param  int  $agentToolDepth  Current nesting depth for agent-as-tool calls
     * @return array<\Prism\Prism\Tool>
     */
    public function execute(Agent $agent, ?Project $project = null, ?string $executionId = null, ?string $sidecarSessionId = null, int $agentToolDepth = 0, ?string $userId = null): array
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

        return $prismTools;
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
                            'parent_agent' => $teamId,
                            'callable_agent_id' => $agentId,
                            'error' => $e->getMessage(),
                        ]);

                        return json_encode(['error' => 'Agent execution failed']);
                    }
                });
        }

        return $tools;
    }
}
