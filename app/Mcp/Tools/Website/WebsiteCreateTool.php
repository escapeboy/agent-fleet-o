<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\CreateWebsiteAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WebsiteCreateTool extends Tool
{
    protected string $name = 'website_create';

    protected string $description = 'Create a new blank website. Use website_generate to AI-generate a full site from a prompt instead.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Website display name')
                ->required(),
            'slug' => $schema->string()
                ->description('URL slug (lowercase letters, numbers, hyphens). Auto-generated from name if omitted.'),
            'custom_domain' => $schema->string()
                ->description('Custom domain (e.g. www.example.com)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:100|regex:/^[a-z0-9-]*$/',
            'custom_domain' => 'nullable|string|max:255',
        ]);

        try {
            $website = app(CreateWebsiteAction::class)->execute(
                teamId: $teamId,
                name: $validated['name'],
                data: [
                    'slug' => $validated['slug'] ?? null,
                    'custom_domain' => $validated['custom_domain'] ?? null,
                ],
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
