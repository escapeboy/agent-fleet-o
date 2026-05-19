<?php

namespace App\Mcp\Tools\Broadcast;

use App\Domain\Audience\Models\Audience;
use App\Domain\Broadcast\Actions\CreateBroadcast;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class BroadcastCreateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'broadcast_create';

    protected string $description = 'Create a draft broadcast for an audience. The broadcast must then be submitted for approval before it can be sent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'audience_id' => $schema->string()
                ->description('Target audience UUID')
                ->required(),
            'name' => $schema->string()
                ->description('Internal broadcast name')
                ->required(),
            'subject' => $schema->string()
                ->description('Email subject line')
                ->required(),
            'body' => $schema->string()
                ->description('Email body (HTML)')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'audience_id' => 'required|string',
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->failedPreconditionError('No team context available.');
        }

        $audience = Audience::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['audience_id']);

        if (! $audience) {
            return $this->notFoundError('audience', $validated['audience_id']);
        }

        $broadcast = app(CreateBroadcast::class)->execute(
            audience: $audience,
            name: $validated['name'],
            subject: $validated['subject'],
            body: $validated['body'],
        );

        return Response::text(json_encode([
            'id' => $broadcast->id,
            'name' => $broadcast->name,
            'status' => $broadcast->status->value,
        ]));
    }
}
