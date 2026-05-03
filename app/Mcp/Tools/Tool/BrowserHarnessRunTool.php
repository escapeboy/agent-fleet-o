<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Services\BuiltIn\BrowserHarnessHandler;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Run a browser task via the self-healing CDP harness (build #4, Trendshift top-5 sprint).
 */
#[AssistantTool('write')]
class BrowserHarnessRunTool extends Tool
{
    protected string $name = 'browser_harness_run';

    protected string $description = 'Run a browser task in a sandboxed Chrome via the self-healing harness. Provide a natural-language task description and (optionally) Python helper code to add new capabilities mid-task. Returns stdout/stderr. Set persist_helpers=true to queue helper additions for human review on the linked Toolset.';

    public function schema(JsonSchema $schema): array
    {
        $teamId = (string) (app('mcp.team_id') ?? auth()->user()?->current_team_id ?? '');

        return [
            'task' => $schema->string()->required()
                ->description('Natural-language description of what to do in the browser.'),
            'helpers_diff' => $schema->string()
                ->description('Optional Python source appended to helpers.py for this run. Use to add capabilities the starter helpers do not provide.'),
            'persist_helpers' => $schema->boolean()
                ->description('If true, queue helpers_diff for human review; on approval the helper becomes part of the linked Toolset.')
                ->default(false),
            'toolset_id' => $schema->string()
                ->description('Optional Toolset UUID to pull approved helpers from / stage new helpers to.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'no_team_resolved']));
        }

        $validated = $request->validate([
            'task' => 'required|string|min:5|max:1000',
            'helpers_diff' => 'nullable|string|max:20000',
            'persist_helpers' => 'nullable|boolean',
            'toolset_id' => "nullable|uuid|exists:toolsets,id,team_id,{$teamId}",
        ]);

        $result = app(BrowserHarnessHandler::class)->execute($validated, (string) $teamId);

        return Response::text(json_encode($result));
    }
}
