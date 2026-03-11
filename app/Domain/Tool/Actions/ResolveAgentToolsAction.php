<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Credential\Models\Credential;
use App\Domain\Agent\Services\SandboxedWorkspace;
use App\Domain\Project\Enums\ProjectExecutionMode;
use App\Domain\Project\Models\Project;
use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\TeamToolActivation;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\ToolTranslator;
use App\Infrastructure\Encryption\CredentialEncryption;
use App\Livewire\Settings\SecurityPolicyPanel;

class ResolveAgentToolsAction
{
    public function __construct(
        private readonly ToolTranslator $translator,
    ) {}

    /**
     * Resolve all PrismPHP Tool objects available for an agent execution.
     *
     * @return array<\Prism\Prism\Tool>
     */
    public function execute(Agent $agent, ?Project $project = null, ?string $executionId = null): array
    {
        $workspace = ($executionId && $agent->team_id)
            ? new SandboxedWorkspace($executionId, $agent->id, $agent->team_id)
            : null;

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
}
