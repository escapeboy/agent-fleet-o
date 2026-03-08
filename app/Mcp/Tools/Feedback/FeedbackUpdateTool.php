<?php

namespace App\Mcp\Tools\Feedback;

use Cloud\Domain\Feedback\Enums\FeedbackStatus;
use Cloud\Domain\Feedback\Models\FeedbackSubmission;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class FeedbackUpdateTool extends Tool
{
    protected string $name = 'feedback_update';

    protected string $description = 'Update a feedback submission status, priority, or admin notes. Super admin only.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Feedback submission UUID'),
            'status' => $schema->string()
                ->description('New status: new, in_review, in_progress, resolved, wont_fix, duplicate')
                ->nullable(),
            'priority' => $schema->string()
                ->description('Internal priority: low, medium, high, critical')
                ->nullable(),
            'admin_notes' => $schema->string()
                ->description('Internal admin notes')
                ->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $submission = FeedbackSubmission::withoutGlobalScopes()
            ->find($request->get('id'));

        if (! $submission) {
            return Response::text(json_encode(['error' => 'Feedback submission not found.']));
        }

        $data = [];

        if ($status = $request->get('status')) {
            $newStatus = FeedbackStatus::tryFrom($status);

            if (! $newStatus) {
                return Response::text(json_encode(['error' => "Invalid status: {$status}"]));
            }

            $data['status'] = $newStatus;

            if ($newStatus === FeedbackStatus::Resolved && ! $submission->resolved_at) {
                $data['resolved_at'] = now();
            }
        }

        if ($priority = $request->get('priority')) {
            $data['priority'] = $priority;
        }

        if ($request->has('admin_notes')) {
            $data['admin_notes'] = $request->get('admin_notes') ?: null;
        }

        $submission->update($data);

        return Response::text(json_encode([
            'id' => $submission->id,
            'status' => $submission->fresh()->status->value,
            'priority' => $submission->fresh()->priority,
            'admin_notes' => $submission->fresh()->admin_notes,
        ]));
    }
}
