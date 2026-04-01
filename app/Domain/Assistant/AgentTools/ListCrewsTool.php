<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Crew\Models\Crew;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListCrewsTool implements Tool
{
    public function name(): string
    {
        return 'list_crews';
    }

    public function description(): string
    {
        return 'List crews (multi-agent teams)';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Filter by status'),
            'limit' => $schema->integer()->description('Max results to return (default 10)'),
        ];
    }

    public function handle(Request $request): string
    {
        $query = Crew::query()->withCount('members')->orderBy('name');

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        $crews = $query->limit($request->get('limit', 10))->get();

        return json_encode([
            'count' => $crews->count(),
            'crews' => $crews->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status->value,
                'members_count' => $c->members_count,
            ])->toArray(),
        ]);
    }
}
