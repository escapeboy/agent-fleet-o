<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Services\McpConfigDiscovery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ToolDiscoverMcpTool extends Tool
{
    protected string $name = 'tool_discover_mcp';

    protected string $description = 'Scan the host machine for MCP server configurations from IDEs (Claude Desktop, Cursor, VS Code, etc.). Returns a preview of discovered servers.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'source' => $schema->string()
                ->description('Scan a specific IDE only: claude_desktop, claude_code, cursor, windsurf, kiro, vscode')
                ->enum(['claude_desktop', 'claude_code', 'cursor', 'windsurf', 'kiro', 'vscode']),
        ];
    }

    public function handle(Request $request): Response
    {
        $discovery = app(McpConfigDiscovery::class);
        $source = $request->get('source');

        if ($source) {
            $result = $discovery->scanSource($source);
            $servers = $result['servers'];
            $sources = ! empty($servers)
                ? [$source => ['label' => $discovery->allSourceLabels()[$source] ?? $source, 'count' => count($servers)]]
                : [];
        } else {
            $scanResult = $discovery->scanAllSources();
            $servers = $scanResult['servers'];
            $sources = $scanResult['sources'];
        }

        return Response::text(json_encode([
            'total' => count($servers),
            'sources' => $sources,
            'servers' => array_map(fn ($s) => [
                'name' => $s['name'],
                'slug' => $s['slug'],
                'source' => $s['source'],
                'type' => $s['type'],
                'disabled' => $s['disabled'],
                'has_credentials' => ! empty($s['credentials']),
                'warnings' => $s['warnings'],
            ], $servers),
        ]));
    }
}
