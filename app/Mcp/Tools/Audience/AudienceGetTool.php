<?php

namespace App\Mcp\Tools\Audience;

use App\Domain\Audience\Models\Audience;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class AudienceGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'audience_get';

    protected string $description = 'Get one audience with its subscription breakdown (subscribed / unsubscribed / pending counts).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'audience_id' => $schema->string()
                ->description('The audience UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['audience_id' => 'required|string']);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->failedPreconditionError('No team context available.');
        }

        $audience = Audience::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['audience_id']);

        if (! $audience) {
            return $this->notFoundError('audience', $validated['audience_id']);
        }

        $breakdown = $audience->members()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Response::text(json_encode([
            'id' => $audience->id,
            'name' => $audience->name,
            'slug' => $audience->slug,
            'topic' => $audience->topic,
            'description' => $audience->description,
            'members' => [
                'subscribed' => (int) ($breakdown['subscribed'] ?? 0),
                'unsubscribed' => (int) ($breakdown['unsubscribed'] ?? 0),
                'pending' => (int) ($breakdown['pending'] ?? 0),
            ],
            'created_at' => $audience->created_at?->toIso8601String(),
        ]));
    }
}
