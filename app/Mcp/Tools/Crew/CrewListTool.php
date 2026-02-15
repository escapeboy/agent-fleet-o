<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\Crew;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class CrewListTool extends Tool
{
    protected string $name = 'crew_list';

    protected string $description = 'List crews with optional status filter. Returns id, name, status, and member count.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: draft, active, archived')
                ->enum(['draft', 'active', 'archived']),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = Crew::query()->withCount('members')->orderBy('name');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);

        $crews = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $crews->count(),
            'crews' => $crews->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status->value,
                'members_count' => $c->members_count,
            ])->toArray(),
        ]));
    }
}
