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
class WebsiteGetTool extends Tool
{
    protected string $name = 'website_get';

    protected string $description = 'Get a website by ID including its pages.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()->description('The website UUID'),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::with('pages')->find($request->get('website_id'));

        if (! $website) {
            return Response::text(json_encode(['error' => 'Website not found'], JSON_PRETTY_PRINT));
        }

        return Response::text(json_encode([
            'id' => $website->id,
            'name' => $website->name,
            'slug' => $website->slug,
            'status' => $website->status instanceof \BackedEnum ? $website->status->value : $website->status,
            'custom_domain' => $website->custom_domain,
            'settings' => $website->settings,
            'created_at' => $website->created_at?->toIso8601String(),
            'updated_at' => $website->updated_at?->toIso8601String(),
            'pages' => $website->pages->map(fn ($p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'title' => $p->title,
                'status' => $p->status instanceof \BackedEnum ? $p->status->value : $p->status,
                'sort_order' => $p->sort_order,
            ]),
        ], JSON_PRETTY_PRINT));
    }
}
