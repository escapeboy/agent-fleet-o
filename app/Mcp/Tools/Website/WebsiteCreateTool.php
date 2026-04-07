<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\CreateWebsiteAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class WebsiteCreateTool extends Tool
{
    protected string $name = 'website_create';

    protected string $description = 'Create a new website for the current team.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Website display name (required)'),
            'slug' => $schema->string()->description('URL slug (auto-generated from name if omitted)'),
            'custom_domain' => $schema->string()->description('Optional custom domain (e.g. example.com)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $team = auth()->user()->currentTeam;

        $website = app(CreateWebsiteAction::class)->execute($team, [
            'name' => $request->get('name'),
            'slug' => $request->get('slug'),
            'custom_domain' => $request->get('custom_domain'),
        ]);

        return Response::text(json_encode([
            'id' => $website->id,
            'name' => $website->name,
            'slug' => $website->slug,
            'status' => $website->status instanceof \BackedEnum ? $website->status->value : $website->status,
            'custom_domain' => $website->custom_domain,
            'created_at' => $website->created_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }
}
