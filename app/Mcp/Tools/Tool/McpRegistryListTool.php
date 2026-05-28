<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Models\McpServerRegistry;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class McpRegistryListTool extends Tool
{
    protected string $name = 'mcp_registry_list';

    protected string $description = 'List MCP servers in the platform-curated registry. These are admin-approved servers that any team may install. Returns id, slug, name, transport, trust_level, description, and is_active.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'trust_level' => $schema->string()
                ->description('Filter by trust level: platform_trusted, verified, community')
                ->enum(['platform_trusted', 'verified', 'community']),
            'active_only' => $schema->boolean()
                ->description('When true (default), only return is_active entries')
                ->default(true),
            'search' => $schema->string()
                ->description('Substring match against name/description'),
            'limit' => $schema->integer()
                ->description('Max results (default 25, max 100)')
                ->default(25),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = McpServerRegistry::query()->orderBy('name');

        if ($trust = $request->get('trust_level')) {
            $query->where('trust_level', $trust);
        }

        if ($request->get('active_only', true)) {
            $query->where('is_active', true);
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('description', 'ilike', '%'.$search.'%');
            });
        }

        $limit = min((int) ($request->get('limit', 25)), 100);

        $entries = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $entries->count(),
            'entries' => $entries->map(fn (McpServerRegistry $e) => [
                'id' => $e->id,
                'slug' => $e->slug,
                'name' => $e->name,
                'description' => $e->description,
                'transport' => $e->transport,
                'trust_level' => $e->trust_level?->value,
                'is_active' => $e->is_active,
                'tool_allowlist' => $e->tool_allowlist,
            ])->toArray(),
        ]));
    }
}
