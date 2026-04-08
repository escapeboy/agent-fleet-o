<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\GenerateWebsiteFromPromptAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WebsiteGenerateTool extends Tool
{
    protected string $name = 'website_generate';

    protected string $description = 'Generate a website from a natural language prompt. Creates site structure with pages using AI.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()->description('Description of the website to create (required)'),
            'name' => $schema->string()->description('Website name (required)'),
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            $website = app(GenerateWebsiteFromPromptAction::class)->execute(
                auth()->user()->currentTeam,
                $request->get('prompt'),
                $request->get('name'),
            );

            return Response::text(json_encode([
                'website_id' => $website->id,
                'name' => $website->name,
                'slug' => $website->slug,
                'pages_created' => $website->pages->count(),
                'pages' => $website->pages->map(fn ($p) => ['id' => $p->id, 'slug' => $p->slug, 'title' => $p->title])->toArray(),
            ], JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            return Response::text(json_encode(['error' => $e->getMessage()]));
        }
    }
}
