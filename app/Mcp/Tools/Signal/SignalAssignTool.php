<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Actions\AssignSignalAction;
use App\Domain\Signal\Models\Signal;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class SignalAssignTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'signal_assign';

    protected string $description = 'Assign or unassign a signal to a team member. Pass assignee_user_id=null to unassign.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_id' => $schema->string()
                ->description('The signal UUID')
                ->required(),
            'assignee_user_id' => $schema->string()
                ->description('User UUID to assign to, or null to unassign'),
            'reason' => $schema->string()
                ->description('Optional note added as an internal comment'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'signal_id' => 'required|string',
            'assignee_user_id' => 'nullable|string',
            'reason' => 'nullable|string|max:2000',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $signal = Signal::withoutGlobalScopes()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->find($validated['signal_id']);

        if (! $signal) {
            return $this->notFoundError('signal');
        }

        $actor = auth()->user();

        if (! $actor && $teamId) {
            $actor = User::whereHas('teams', fn ($q) => $q->where('teams.id', $teamId)
                ->whereIn('team_user.role', ['owner'])
            )->first();
        }

        if (! $actor) {
            return $this->invalidArgumentError('Could not resolve actor user.');
        }

        $assignee = null;
        if (! empty($validated['assignee_user_id'])) {
            $assignee = User::find($validated['assignee_user_id']);
            if (! $assignee) {
                return $this->notFoundError('assignee user');
            }
        }

        try {
            $signal = app(AssignSignalAction::class)->execute(
                signal: $signal,
                assignee: $assignee,
                actor: $actor,
                reason: $validated['reason'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->invalidArgumentError($e->getMessage());
        }

        return Response::text(json_encode([
            'id' => $signal->id,
            'assigned_user_id' => $signal->assigned_user_id,
            'assigned_at' => $signal->assigned_at?->toIso8601String(),
        ]));
    }
}
