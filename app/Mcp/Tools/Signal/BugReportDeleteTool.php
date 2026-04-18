<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Models\Signal;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class BugReportDeleteTool extends Tool
{
    protected string $name = 'bug_report_delete';

    protected string $description = 'Permanently delete a bug report signal. Only deletes signals with source_type=bug_report scoped to the current team.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_id' => $schema->string()
                ->description('UUID of the bug report signal to delete')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['signal_id' => 'required|string|uuid']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $report = Signal::withoutGlobalScopes()
            ->where('source_type', 'bug_report')
            ->where('team_id', $teamId)
            ->find($validated['signal_id']);

        if (! $report) {
            return Response::error('Bug report not found.');
        }

        $report->delete();

        return Response::text(json_encode([
            'success' => true,
            'deleted_id' => $validated['signal_id'],
        ]));
    }
}
