<?php

namespace App\Mcp\Concerns;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Shared\Models\Team;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;

/**
 * Resolve a chatbot of the caller's team by UUID or slug, behind the team's
 * chatbot feature gate. Requires HasStructuredErrors on the using tool.
 */
trait ResolvesTeamChatbot
{
    protected function resolveTeamChatbot(string $idOrSlug): Chatbot|Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        if (! (Team::find($teamId)?->settings['chatbot_enabled'] ?? false)) {
            return $this->failedPreconditionError('Chatbot feature is not enabled for this team.');
        }

        $chatbot = Chatbot::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where(Str::isUuid($idOrSlug) ? 'id' : 'slug', $idOrSlug)
            ->first();

        return $chatbot ?? $this->notFoundError('chatbot', $idOrSlug);
    }
}
