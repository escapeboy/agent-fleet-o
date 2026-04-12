<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Tools\DelegationTools;
use App\Domain\Assistant\Tools\GetEntityTools;
use App\Domain\Assistant\Tools\ListEntitiesTools;
use App\Domain\Assistant\Tools\MemoryTools;
use App\Domain\Assistant\Tools\MutationTools;
use App\Domain\Assistant\Tools\SchedulingTools;
use App\Domain\Assistant\Tools\SearchTools;
use App\Domain\Assistant\Tools\SecurityTools;
use App\Domain\Assistant\Tools\StatusTools;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Servers\AgentFleetServer;
use App\Models\User;
use Prism\Prism\Tool as PrismToolObject;
use Prism\Prism\Tools\LaravelMcpTool;
use ReflectionClass;

class AssistantToolRegistry
{
    /**
     * Get all available tools for the given user, filtered by role.
     *
     * @return array<PrismToolObject>
     */
    public function getTools(User $user, ?AssistantConversation $conversation = null): array
    {
        $tools = [];

        // READ tools - always available
        $tools = array_merge($tools, ListEntitiesTools::tools());
        $tools = array_merge($tools, GetEntityTools::tools());
        $tools = array_merge($tools, StatusTools::tools());
        $tools = array_merge($tools, MemoryTools::tools());
        $tools = array_merge($tools, SearchTools::tools());
        $tools = array_merge($tools, $this->bridgedMcpTools('read'));

        // WRITE tools - available to Owner/Admin/Member
        $role = $user->teamRole($user->currentTeam);
        if ($role?->canEdit()) {
            // Security tools expose PII (email, phone, risk flags) — member+ only
            $tools = array_merge($tools, SecurityTools::tools());
            $tools = array_merge($tools, MutationTools::writeTools());
            $tools = array_merge($tools, SchedulingTools::tools($user->currentTeam->id, $user->id));
            $tools = array_merge($tools, $this->bridgedMcpTools('write'));

            // Delegation tools (fire-and-forget async project runs)
            if ($conversation) {
                $tools = array_merge($tools, DelegationTools::tools($conversation->id));
            }
        }

        // DESTRUCTIVE tools - available to Owner/Admin only
        if ($role?->canManageTeam()) {
            $tools = array_merge($tools, MutationTools::destructiveTools());
            $tools = array_merge($tools, $this->bridgedMcpTools('destructive'));
        }

        return $tools;
    }

    /**
     * Wrap all MCP tools annotated with #[AssistantTool(tier)] as PrismPHP tools.
     *
     * Uses PHP reflection on AgentFleetServer's default $tools property so no
     * server instantiation is required, and no separate registry needs maintaining.
     *
     * @return array<PrismToolObject>
     */
    private function bridgedMcpTools(string $tier): array
    {
        $serverReflection = new ReflectionClass(AgentFleetServer::class);
        $toolClasses = $serverReflection->getDefaultProperties()['tools'] ?? [];

        $bridged = [];
        foreach ($toolClasses as $toolClass) {
            $classReflection = new ReflectionClass($toolClass);
            $attrs = $classReflection->getAttributes(AssistantTool::class);
            if (empty($attrs)) {
                continue;
            }
            /** @var AssistantTool $attr */
            $attr = $attrs[0]->newInstance();
            if ($attr->tier === $tier) {
                $bridged[] = new LaravelMcpTool(app($toolClass));
            }
        }

        return $bridged;
    }

    /**
     * Classify a tool name by its tier.
     *
     * For MCP tools annotated with #[AssistantTool], the tier is embedded in the
     * attribute and does not need to be listed here. This method handles the
     * legacy assistant tool names (Mutations/*) and general prefix heuristics.
     */
    public static function toolTier(string $toolName): string
    {
        // MCP-style destructive tools (domain_delete / domain_remove / domain_cancel)
        if (
            str_ends_with($toolName, '_delete') ||
            str_ends_with($toolName, '_remove') ||
            str_ends_with($toolName, '_cancel') ||
            str_ends_with($toolName, '_unpublish') ||
            in_array($toolName, [
                'kill_experiment', 'archive_project',
                'delete_agent', 'delete_memory', 'delete_connector_binding',
                'delete_website', 'delete_website_page',
                'manage_byok_credential', 'manage_api_token',
                'crew_member_remove', 'team_remove_member',
                'project_cancel_run', 'marketplace_unpublish',
            ])
        ) {
            return 'destructive';
        }

        // Legacy prefix-based write detection (create_*, update_*, etc.)
        if (
            str_starts_with($toolName, 'create_') ||
            str_starts_with($toolName, 'execute_') ||
            str_starts_with($toolName, 'update_') ||
            str_starts_with($toolName, 'generate_') ||
            str_starts_with($toolName, 'activate_') ||
            str_starts_with($toolName, 'start_') ||
            str_starts_with($toolName, 'pause_') ||
            str_starts_with($toolName, 'resume_') ||
            str_starts_with($toolName, 'retry_') ||
            str_starts_with($toolName, 'trigger_') ||
            str_starts_with($toolName, 'approve_') ||
            str_starts_with($toolName, 'reject_') ||
            str_starts_with($toolName, 'save_') ||
            str_starts_with($toolName, 'sync_') ||
            str_starts_with($toolName, 'upload_') ||
            str_starts_with($toolName, 'publish_') ||
            str_starts_with($toolName, 'unpublish_') ||
            str_starts_with($toolName, 'deploy_') ||
            str_starts_with($toolName, 'schedule_')
        ) {
            return 'write';
        }

        // MCP-style write tools (domain_clone, domain_activate, domain_deactivate, etc.)
        if (
            str_ends_with($toolName, '_clone') ||
            str_ends_with($toolName, '_activate') ||
            str_ends_with($toolName, '_deactivate') ||
            str_ends_with($toolName, '_invite') ||
            str_ends_with($toolName, '_transfer') ||
            in_array($toolName, [
                'agent_clone', 'project_clone', 'skill_clone',
                'crew_activate', 'workflow_deactivate', 'workflow_delete',
                'budget_add_credits', 'budget_transfer',
                'memory_update', 'experiment_update', 'experiment_skip_stage',
                'team_invite_member', 'team_update_member_role',
            ])
        ) {
            return 'write';
        }

        return 'read';
    }
}
