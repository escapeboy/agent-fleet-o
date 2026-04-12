<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class TeamClaudeCodeVpsAccessTool extends Tool
{
    protected string $name = 'team_claude_code_vps_access';

    protected string $description = 'Super-admin only. Manage VPS Claude Code access for teams. Actions: list (all teams with their VPS flag), enable (grant access to a team), disable (revoke access).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: list | enable | disable')
                ->enum(['list', 'enable', 'disable'])
                ->required(),
            'team_id' => $schema->string()
                ->description('Target team UUID (required for enable and disable).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        if (! $user || ! $user->is_super_admin) {
            return Response::error('Only super admins can manage Claude Code VPS access.');
        }

        $action = $request->get('action');

        return match ($action) {
            'list' => $this->list(),
            'enable' => $this->toggle($request, true),
            'disable' => $this->toggle($request, false),
            default => Response::error("Unknown action: {$action}"),
        };
    }

    private function list(): Response
    {
        $teams = Team::withoutGlobalScopes()
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'claude_code_vps_allowed']);

        return Response::text(json_encode([
            'count' => $teams->count(),
            'allowed_count' => $teams->where('claude_code_vps_allowed', true)->count(),
            'teams' => $teams->map(fn (Team $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'allowed' => $t->claude_code_vps_allowed,
            ])->toArray(),
        ], JSON_PRETTY_PRINT));
    }

    private function toggle(Request $request, bool $allowed): Response
    {
        $teamId = $request->get('team_id');
        if (! $teamId) {
            return Response::error('team_id is required.');
        }

        $team = Team::withoutGlobalScopes()->find($teamId);
        if (! $team) {
            return Response::error("Team {$teamId} not found.");
        }

        $previous = (bool) $team->claude_code_vps_allowed;
        $team->update(['claude_code_vps_allowed' => $allowed]);

        AuditEntry::create([
            'team_id' => $team->id,
            'user_id' => Auth::id(),
            'event' => 'claude_code_vps.access.'.($allowed ? 'granted' : 'revoked'),
            'subject_type' => Team::class,
            'subject_id' => $team->id,
            'properties' => [
                'previous' => $previous,
                'new' => $allowed,
            ],
            'created_at' => now(),
        ]);

        return Response::text(json_encode([
            'team_id' => $team->id,
            'name' => $team->name,
            'allowed' => $allowed,
            'previous' => $previous,
        ]));
    }
}
