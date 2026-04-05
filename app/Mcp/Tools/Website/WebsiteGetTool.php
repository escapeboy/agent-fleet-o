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

    protected string $description = 'Get a website by ID or slug, including its pages list.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Website UUID'),
            'slug' => $schema->string()
                ->description('Website slug'),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = $request->get('id')
            ? Website::with('pages')->find($request->get('id'))
            : Website::with('pages')->where('slug', $request->get('slug'))->first();

        if (! $website) {
            return Response::error('Website not found.');
        }

        return Response::text(json_encode([
            'id' => $website->id,
            'name' => $website->name,
            'slug' => $website->slug,
            'status' => $website->status->value,
            'custom_domain' => $website->custom_domain,
            'settings' => $website->settings,
            'pages' => $website->pages->map(fn ($p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'title' => $p->title,
                'page_type' => $p->page_type->value,
                'status' => $p->status->value,
                'has_content' => ! empty($p->exported_html),
                'sort_order' => $p->sort_order,
            ])->toArray(),
        ]));
    }
}
