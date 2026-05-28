<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\InstallFromRegistryAction;
use App\Domain\Tool\Models\McpServerRegistry;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use RuntimeException;

#[IsDestructive]
#[AssistantTool('write')]
class McpRegistryInstallTool extends Tool
{
    protected string $name = 'mcp_registry_install';

    protected string $description = 'Install an MCP server from the platform registry into the calling team as a Tool row. Idempotent: re-installing returns the existing Tool. Returns the created/existing Tool id and slug.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'registry_id' => $schema->string()
                ->description('UUID of the McpServerRegistry entry to install. Use mcp_registry_list to discover ids.')
                ->required(),
        ];
    }

    public function handle(Request $request, InstallFromRegistryAction $action): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null)
            ?? auth()->user()?->current_team_id;

        if ($teamId === null) {
            throw new RuntimeException('No team context available; mcp_registry_install must be called with team authentication.');
        }

        $entry = McpServerRegistry::query()->findOrFail((string) $request->get('registry_id'));
        $tool = $action->execute($entry, (string) $teamId);

        return Response::text(json_encode([
            'tool_id' => $tool->id,
            'tool_slug' => $tool->slug,
            'registry_slug' => $entry->slug,
            'installed_from_registry' => true,
        ]));
    }
}
