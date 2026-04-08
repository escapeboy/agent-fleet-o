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

    protected string $description = 'Update a website\'s name, slug, status, custom domain, or settings.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()->description('The website UUID (required)'),
            'name' => $schema->string()->description('New display name'),
            'slug' => $schema->string()->description('New URL slug'),
            'status' => $schema->string()->description('New status')->enum(['draft', 'published', 'archived']),
            'custom_domain' => $schema->string()->description('Custom domain (e.g. example.com)'),
            'settings' => $schema->string()->description('JSON-encoded settings object'),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::find($request->get('website_id'));

        if (! $website) {
            return Response::text(json_encode(['error' => 'Website not found'], JSON_PRETTY_PRINT));
        }

        $data = array_filter([
            'name' => $request->get('name'),
            'slug' => $request->get('slug'),
            'status' => $request->get('status'),
            'custom_domain' => $request->get('custom_domain'),
        ], fn ($v) => $v !== null);

        if ($settingsRaw = $request->get('settings')) {
            $data['settings'] = json_decode($settingsRaw, true) ?? [];
        }

        $website = app(UpdateWebsiteAction::class)->execute($website, $data);

        return Response::text(json_encode([
            'id' => $website->id,
            'name' => $website->name,
            'slug' => $website->slug,
            'status' => $website->status instanceof \BackedEnum ? $website->status->value : $website->status,
            'custom_domain' => $website->custom_domain,
            'updated_at' => $website->updated_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }
}
