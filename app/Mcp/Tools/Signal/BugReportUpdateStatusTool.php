<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Actions\UpdateSignalStatusAction;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Exceptions\InvalidSignalTransitionException;
use App\Domain\Signal\Models\Signal;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class BugReportUpdateStatusTool extends Tool
{
    protected string $name = 'bug_report_update_status';

    protected string $description = 'Update the status of a bug report. Valid statuses: received, triaged, in_progress, delegated_to_agent, agent_fixing, review, resolved, dismissed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_id' => $schema->string()
                ->description('UUID of the bug report signal'),
            'status' => $schema->string()
                ->description('New status value'),
            'comment' => $schema->string()
                ->description('Optional comment to add with the status change')
                ->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $signal = Signal::where('source_type', 'bug_report')->find($request->get('signal_id'));

        if (! $signal) {
            return Response::text(json_encode(['error' => 'Bug report not found']));
        }

        $statusValue = $request->get('status');

        try {
            $newStatus = SignalStatus::from($statusValue);
        } catch (\ValueError) {
            return Response::text(json_encode(['error' => "Invalid status: {$statusValue}"]));
        }

        try {
            app(UpdateSignalStatusAction::class)->execute(
                signal: $signal,
                newStatus: $newStatus,
                comment: $request->get('comment'),
            );
        } catch (InvalidSignalTransitionException $e) {
            return Response::text(json_encode(['error' => $e->getMessage()]));
        }

        return Response::text(json_encode([
            'signal_id' => $signal->id,
            'status' => $signal->fresh()->status?->value,
        ]));
    }
}
