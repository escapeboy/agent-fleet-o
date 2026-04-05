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

    protected string $description = 'Generate a complete multi-page website from a natural language prompt using AI. Creates the website and all pages with HTML content.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()
                ->description('Description of the website to generate. Be specific about type, industry, tone, and key sections.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $validated = $request->validate([
            'prompt' => 'required|string|max:2000',
        ]);

        try {
            $website = app(GenerateWebsiteFromPromptAction::class)->execute(
                teamId: $teamId,
                prompt: $validated['prompt'],
            );

            $website->load('pages');

            return Response::text(json_encode([
                'success' => true,
                'website_id' => $website->id,
                'name' => $website->name,
                'slug' => $website->slug,
                'pages' => $website->pages->map(fn ($p) => [
                    'id' => $p->id,
                    'slug' => $p->slug,
                    'title' => $p->title,
                    'page_type' => $p->page_type->value,
                ])->toArray(),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
