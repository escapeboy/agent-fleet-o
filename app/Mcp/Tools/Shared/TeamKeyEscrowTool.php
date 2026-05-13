<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Actions\RecoverTeamKeyAction;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamKeyEscrow;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class TeamKeyEscrowTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'team_key_escrow';

    protected string $description = 'Super-admin only. Manage team credential key escrow. Actions: status (check escrow exists and version for a team), recover (decrypt and return the team credential key for emergency re-issue).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: status | recover')
                ->enum(['status', 'recover'])
                ->required(),
            'team_id' => $schema->string()
                ->description('Target team UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        if (! $user || ! $user->is_super_admin) {
            return $this->permissionDeniedError('Only super admins can access key escrow.');
        }

        $validated = $request->validate([
            'action' => 'required|string|in:status,recover',
            'team_id' => 'required|string',
        ]);

        $team = Team::withoutGlobalScopes()->find($validated['team_id']);
        if (! $team) {
            return $this->notFoundError('team');
        }

        return match ($validated['action']) {
            'status' => $this->status($team),
            'recover' => $this->recover($team),
        };
    }

    private function status(Team $team): Response
    {
        $escrow = TeamKeyEscrow::where('team_id', $team->id)->first();

        return Response::text(json_encode([
            'team_id' => $team->id,
            'team_name' => $team->name,
            'escrow_exists' => $escrow !== null,
            'share_version' => $escrow?->share_version,
            'created_at' => $escrow?->created_at?->toIso8601String(),
            'updated_at' => $escrow?->updated_at?->toIso8601String(),
        ]));
    }

    private function recover(Team $team): Response
    {
        try {
            $recoveredKey = app(RecoverTeamKeyAction::class)->execute($team);

            Log::warning('Team credential key recovered via MCP escrow tool', [
                'team_id' => $team->id,
                'admin_id' => Auth::id(),
            ]);

            return Response::text(json_encode([
                'team_id' => $team->id,
                'team_name' => $team->name,
                'recovered_credential_key' => $recoveredKey,
                'warning' => 'Rotate this key immediately after re-issuing to the team. This operation has been logged.',
            ]));
        } catch (\RuntimeException $e) {
            return $this->invalidArgumentError($e->getMessage());
        }
    }
}
