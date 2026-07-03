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
class AudienceListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'audience_list';

    protected string $description = 'List the team\'s contact audiences with member and subscriber counts. Optionally filter by subscription topic.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()
                ->description('Filter audiences by subscription topic'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->failedPreconditionError('No team context available.');
        }

        $audiences = Audience::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->when($request->get('topic'), fn ($q, $topic) => $q->where('topic', $topic))
            ->withCount(['members', 'subscribedMembers'])
            ->orderBy('name')
            ->get();

        return Response::text(json_encode([
            'count' => $audiences->count(),
            'audiences' => $audiences->map(fn (Audience $a): array => [
                'id' => $a->id,
                'name' => $a->name,
                'slug' => $a->slug,
                'topic' => $a->topic,
                'description' => $a->description,
                'members' => $a->members_count,
                'subscribed' => $a->subscribed_members_count,
            ])->toArray(),
        ]));
    }
}
