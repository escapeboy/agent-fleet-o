<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\Tools\GetEntityTools;
use App\Domain\Assistant\Tools\ListEntitiesTools;
use App\Domain\Assistant\Tools\MutationTools;
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
    public function getTools(User $user): array
    {
        $tools = [];

        // READ tools - always available
        $tools = array_merge($tools, ListEntitiesTools::tools());
        $tools = array_merge($tools, GetEntityTools::tools());
        $tools = array_merge($tools, StatusTools::tools());

        // WRITE tools - available to Owner/Admin/Member
        $role = $user->teamRole($user->currentTeam);
        if ($role?->canEdit()) {
            $tools = array_merge($tools, MutationTools::writeTools());
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
            in_array($toolName, ['kill_experiment', 'archive_project']) => 'destructive',
            str_starts_with($toolName, 'create_') ||
            str_starts_with($toolName, 'pause_') ||
            str_starts_with($toolName, 'resume_') ||
            str_starts_with($toolName, 'retry_') ||
            str_starts_with($toolName, 'trigger_') ||
            str_starts_with($toolName, 'approve_') ||
            str_starts_with($toolName, 'reject_') => 'write',
            default => 'read',
        };
    }
}
