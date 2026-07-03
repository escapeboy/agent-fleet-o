<?php

namespace App\Mcp\Tools\Audience;

use App\Domain\Audience\Actions\CreateAudience;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AudienceCreateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'audience_create';

    protected string $description = 'Create a contact audience. An optional topic groups audiences into an unsubscribe category.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Audience name')
                ->required(),
            'description' => $schema->string()
                ->description('Optional description'),
            'topic' => $schema->string()
                ->description('Optional subscription topic (e.g. newsletter, product_updates)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'topic' => 'nullable|string|max:255',
        ]);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->failedPreconditionError('No team context available.');
        }

        $audience = app(CreateAudience::class)->execute(
            teamId: $teamId,
            name: $validated['name'],
            description: $validated['description'] ?? null,
            topic: $validated['topic'] ?? null,
        );

        return Response::text(json_encode([
            'id' => $audience->id,
            'name' => $audience->name,
            'slug' => $audience->slug,
            'topic' => $audience->topic,
        ]));
    }
}
