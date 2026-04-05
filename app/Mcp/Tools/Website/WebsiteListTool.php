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

    protected string $description = 'List websites for the current team. Returns id, name, slug, status, page count, and custom domain.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: draft, published, archived')
                ->enum(['draft', 'published', 'archived']),
            'limit' => $schema->integer()
                ->description('Max results (default 20, max 100)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = Website::query()->withCount('pages')->orderBy('name');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $limit = min((int) ($request->get('limit', 20)), 100);
        $websites = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $websites->count(),
            'websites' => $websites->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
                'status' => $w->status->value,
                'pages_count' => $w->pages_count,
                'custom_domain' => $w->custom_domain,
            ])->toArray(),
        ]));
    }
}
