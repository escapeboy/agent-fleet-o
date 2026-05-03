<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class CrewMemberSetModelOverrideTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'crew_member_set_model_override';

    protected string $description = 'Set or clear the per-role model override for a crew member. The override applies only when the member is invoked through ProviderResolver::forCrewRole(), letting different roles (Planner, Worker, Judge) use different models. Pass provider+model to set, or omit both to clear.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()
                ->description('Crew UUID owning the member')
                ->required(),
            'member_id' => $schema->string()
                ->description('CrewMember UUID')
                ->required(),
            'provider' => $schema->string()
                ->description('Provider name (anthropic, openai, google, claude-code, codex, etc). Omit to clear.'),
            'model' => $schema->string()
                ->description('Model identifier (e.g. claude-haiku-4-5, gpt-4o). Required when provider is set.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'crew_id' => 'required|string',
            'member_id' => 'required|string',
            'provider' => 'nullable|string|max:64',
            'model' => 'nullable|string|max:128',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $crew = Crew::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['crew_id']);
        if (! $crew) {
            return $this->notFoundError('crew');
        }

        $member = CrewMember::query()
            ->where('crew_id', $crew->id)
            ->where('id', $validated['member_id'])
            ->first();
        if (! $member) {
            return $this->notFoundError('crew_member');
        }

        $config = $member->config ?? [];
        $provider = $validated['provider'] ?? null;
        $model = $validated['model'] ?? null;

        if ($provider !== null && $provider !== '') {
            if ($model === null || $model === '') {
                return $this->validationError('model is required when provider is set');
            }
            $config['model_override'] = ['provider' => $provider, 'model' => $model];
            $action = 'set';
        } else {
            unset($config['model_override']);
            $action = 'cleared';
        }

        $member->update(['config' => $config]);

        return Response::json([
            'ok' => true,
            'action' => $action,
            'member_id' => $member->id,
            'model_override' => $member->fresh()->model_override,
        ]);
    }
}
