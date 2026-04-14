<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\ImportMcpServersAction;
use App\Domain\Tool\Services\McpConfigDiscovery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use App\Mcp\Attributes\AssistantTool;

#[IsDestructive]
#[AssistantTool('write')]
class ToolImportMcpTool extends Tool
{
    protected string $name = 'tool_import_mcp';

    protected string $description = 'Import discovered MCP servers from host machine into the Tools inventory. First run tool_discover_mcp to see available servers.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'source' => $schema->string()
                ->description('Import from a specific IDE only: claude_desktop, claude_code, cursor, windsurf, kiro, vscode'),
            'skip_existing' => $schema->boolean()
                ->description('Skip servers that already exist (default: true)')
                ->default(true),
            'include_disabled' => $schema->boolean()
                ->description('Include servers marked as disabled in source config (default: false)')
                ->default(false),
        ];
    }

    public function handle(Request $request): Response
    {
        $discovery = app(McpConfigDiscovery::class);
        $source = $request->get('source');

        if ($source) {
            $result = $discovery->scanSource($source);
            $servers = $result['servers'];
        } else {
            $scanResult = $discovery->scanAllSources();
            $servers = $scanResult['servers'];
        }

        if (empty($servers)) {
            return Response::text(json_encode([
                'success' => false,
                'message' => 'No MCP servers found to import.',
            ]));
        }

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        $importer = app(ImportMcpServersAction::class);

        $importResult = $importer->execute(
            teamId: $teamId,
            servers: $servers,
            skipExisting: (bool) $request->get('skip_existing', true),
            importDisabled: (bool) $request->get('include_disabled', false),
        );

        return Response::text(json_encode([
            'success' => true,
            'imported' => $importResult->imported,
            'skipped' => $importResult->skipped,
            'failed' => $importResult->failed,
            'details' => $importResult->details,
        ]));
    }
}
