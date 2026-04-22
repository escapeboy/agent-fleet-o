<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class TeamModelAllowlistTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'team_model_allowlist_update';

    protected string $description = 'Update the team model allowlist. Pass an empty array to allow all models. Pass an array of "provider/model" strings (e.g. "anthropic/claude-sonnet-4-5") to restrict.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'allowed_models' => $schema->array()
                ->items($schema->string())
                ->description('Array of allowed "provider/model" strings. Empty array = all models allowed.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $team = Team::withoutGlobalScopes()->find($teamId);
        if (! $team) {
            return $this->notFoundError('team');
        }

        $user = auth()->user();
        if ($user) {
            $member = $team->users()->where('users.id', $user->id)->first();
            $role = $member?->pivot?->role;
            if (! in_array($role, ['owner', 'admin'], true)) {
                return $this->permissionDeniedError('Only team owners and admins can update the model allowlist.');
            }
        }

        $allowedModels = $request->get('allowed_models');
        $team->update(['allowed_models' => empty($allowedModels) ? null : $allowedModels]);

        return Response::text(json_encode([
            'success' => true,
            'allowed_models' => $team->allowed_models,
        ]));
    }
}
