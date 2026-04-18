<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WebsiteListTool extends Tool
{
    protected string $name = 'website_list';

    protected string $description = 'List websites for the current team. Optionally filter by status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status')
                ->enum(['draft', 'published', 'archived']),
            'limit' => $schema->integer()
                ->description('Max results (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = Website::query()->orderBy('name');

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        $limit = min((int) ($request->get('limit') ?? 10), 100);
        $websites = $query->withCount('pages')->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $websites->count(),
            'websites' => $websites->map(fn (Website $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
                'status' => $w->status instanceof \BackedEnum ? $w->status->value : $w->status,
                'custom_domain' => $w->custom_domain,
                'page_count' => $w->pages_count,
                'created_at' => $w->created_at?->toIso8601String(),
            ]),
        ], JSON_PRETTY_PRINT));
    }
}
