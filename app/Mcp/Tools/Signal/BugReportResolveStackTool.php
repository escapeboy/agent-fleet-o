<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Actions\ResolveStackTraceAction;
use App\Domain\Signal\Models\Signal;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsDestructive]
#[IsIdempotent]
#[AssistantTool('write')]
class BugReportResolveStackTool extends Tool
{
    protected string $name = 'bug_report_resolve_stack';

    protected string $description = 'Manually trigger source map resolution for a bug report. Resolves minified JavaScript stack frames to original source file:line locations and stores the result in the signal.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_id' => $schema->string()
                ->description('UUID of the bug report signal to resolve'),
        ];
    }

    public function handle(Request $request): Response
    {
        $signal = Signal::where('source_type', 'bug_report')
            ->find($request->get('signal_id'));

        if (! $signal) {
            return Response::text(json_encode(['error' => 'Bug report not found']));
        }

        app(ResolveStackTraceAction::class)->execute($signal);
        $signal->refresh();

        $resolvedErrors = $signal->payload['resolved_errors'] ?? [];

        return Response::text(json_encode([
            'signal_id' => $signal->id,
            'resolved_errors_count' => count($resolvedErrors),
            'resolved_errors' => $resolvedErrors,
        ]));
    }
}
