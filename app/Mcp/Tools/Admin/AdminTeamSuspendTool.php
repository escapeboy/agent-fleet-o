<?php

namespace App\Mcp\Tools\Admin;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\DeploymentMode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AdminTeamSuspendTool extends Tool
{
    protected string $name = 'admin_team_suspend';

    protected string $description = 'Suspend or reactivate a team. Suspended teams are blocked from accessing the platform. Super admin only.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'team_id' => $schema->string()
                ->description('UUID of the team to suspend or reactivate')
                ->required(),
            'action' => $schema->string()
                ->description('Action to take: suspend or reactivate')
                ->enum(['suspend', 'reactivate'])
                ->required(),
            'reason' => $schema->string()
                ->description('Reason for suspension (required when suspending)'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (app(DeploymentMode::class)->isCloud() && ! auth()->user()?->is_super_admin) {
            return Response::error('Access denied: super admin privileges required.');
        }

        $team = Team::withoutGlobalScopes()->findOrFail($request->get('team_id'));
        $action = $request->get('action');

        if ($action === 'suspend') {
            $reason = $request->get('reason', 'Suspended by admin');
            $team->update(['suspended_at' => now(), 'suspension_reason' => $reason]);

            return Response::text(json_encode([
                'success' => true,
                'message' => "Team '{$team->name}' suspended.",
                'suspended_at' => $team->suspended_at->toIso8601String(),
                'reason' => $reason,
            ]));
        }

        $team->update(['suspended_at' => null, 'suspension_reason' => null]);

        return Response::text(json_encode([
            'success' => true,
            'message' => "Team '{$team->name}' reactivated.",
        ]));
    }
}
