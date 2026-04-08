<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Services\WebsiteZipBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WebsiteExportTool extends Tool
{
    protected string $name = 'website_export';

    protected string $description = 'Export a website as a ZIP file containing all published pages and assets.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()->description('The website UUID to export (required)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::find($request->get('website_id'));

        if (! $website) {
            return Response::text(json_encode(['error' => 'Website not found'], JSON_PRETTY_PRINT));
        }

        $zipPath = app(WebsiteZipBuilder::class)->build($website);

        return Response::text(json_encode([
            'zip_path' => $zipPath,
            'message' => "ZIP created at {$zipPath}",
        ], JSON_PRETTY_PRINT));
    }
}
