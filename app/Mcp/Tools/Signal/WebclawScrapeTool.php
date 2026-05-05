<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Connectors\WebScrapingConnector;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class WebclawScrapeTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'webclaw_scrape';

    protected string $description = 'Scrape a URL and ingest the extracted content as a signal';

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('URL to scrape')
                ->required(),
            'format' => $schema->string()
                ->description('Output format: markdown, text, or json (default: markdown)')
                ->default('markdown'),
            'tags' => $schema->array()
                ->description('Tags to attach to the ingested signal')
                ->items($schema->string()),
        ];
    }

    public function handle(Request $request): Response
    {
        $url = $request->get('url');
        if (empty($url)) {
            return $this->invalidArgumentError('url is required.');
        }

        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : null;

        $signals = app(WebScrapingConnector::class)->poll([
            'url' => $url,
            'format' => $request->get('format') ?? 'markdown',
            'tags' => $request->get('tags') ?? ['webclaw'],
            '_team_id' => $teamId,
        ]);

        if (empty($signals)) {
            return Response::text(json_encode([
                'success' => false,
                'message' => 'No signal ingested. The URL may have failed, been deduplicated, or was blacklisted.',
            ]));
        }

        return Response::text(json_encode([
            'success' => true,
            'signal_id' => $signals[0]->id,
            'url' => $url,
        ]));
    }
}
