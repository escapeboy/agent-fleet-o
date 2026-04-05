<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\UpdateWebsiteAction;
use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WebsiteUpdateTool extends Tool
{
    protected string $name = 'website_update';

    protected string $description = 'Update a website\'s name, slug, status, or custom domain.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()
                ->description('Website UUID')
                ->required(),
            'name' => $schema->string()
                ->description('New display name'),
            'slug' => $schema->string()
                ->description('New URL slug'),
            'status' => $schema->string()
                ->description('New status')
                ->enum(['draft', 'published', 'archived']),
            'custom_domain' => $schema->string()
                ->description('Custom domain (e.g. www.example.com)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::find($request->get('website_id'));
        if (! $website) {
            return Response::error('Website not found.');
        }

        try {
            $website = app(UpdateWebsiteAction::class)->execute(
                website: $website,
                data: array_filter([
                    'name' => $request->get('name'),
                    'slug' => $request->get('slug'),
                    'status' => $request->get('status'),
                    'custom_domain' => $request->get('custom_domain'),
                ], fn ($v) => $v !== null),
            );

            return Response::text(json_encode([
                'success' => true,
                'website_id' => $website->id,
                'name' => $website->name,
                'slug' => $website->slug,
                'status' => $website->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
