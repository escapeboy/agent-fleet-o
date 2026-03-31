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
use App\Models\User;
use Prism\Prism\Tool as PrismToolObject;

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

        // WRITE tools - available to Owner/Admin/Member
        $role = $user->teamRole($user->currentTeam);
        if ($role?->canEdit()) {
            // Security tools expose PII (email, phone, risk flags) — member+ only
            $tools = array_merge($tools, SecurityTools::tools());
            $tools = array_merge($tools, MutationTools::writeTools());
            $tools = array_merge($tools, SchedulingTools::tools($user->currentTeam->id, $user->id));

            // Delegation tools (fire-and-forget async project runs)
            if ($conversation) {
                $tools = array_merge($tools, DelegationTools::tools($conversation->id));
            }
        }

        // DESTRUCTIVE tools - available to Owner/Admin only
        if ($role?->canManageTeam()) {
            $tools = array_merge($tools, MutationTools::destructiveTools());
        }

        return $tools;
    }

    /**
     * Classify a tool name by its tier.
     */
    public static function toolTier(string $toolName): string
    {
        return match (true) {
            in_array($toolName, [
                'kill_experiment', 'archive_project',
                'delete_agent', 'delete_memory', 'delete_connector_binding',
                'manage_byok_credential', 'manage_api_token',
            ]) => 'destructive',
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
            str_starts_with($toolName, 'schedule_') => 'write',
            default => 'read',
        };
    }
}
