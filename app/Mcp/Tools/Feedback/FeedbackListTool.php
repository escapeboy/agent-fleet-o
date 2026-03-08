<?php

namespace App\Mcp\Tools\Feedback;

use Cloud\Domain\Feedback\Models\FeedbackSubmission;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class FeedbackListTool extends Tool
{
    protected string $name = 'feedback_list';

    protected string $description = 'List user feedback submissions (bug reports and feature requests). Super admin only.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->description('Filter by type: bug or feature_request')
                ->nullable(),
            'status' => $schema->string()
                ->description('Filter by status: new, in_review, in_progress, resolved, wont_fix, duplicate')
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Maximum number of results to return')
                ->default(50),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = FeedbackSubmission::withoutGlobalScopes()
            ->with('user')
            ->orderByDesc('created_at');

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $limit = min((int) ($request->get('limit') ?? 50), 200);
        $submissions = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $submissions->count(),
            'submissions' => $submissions->map(fn (FeedbackSubmission $s) => [
                'id' => $s->id,
                'type' => $s->type->value,
                'title' => $s->title,
                'severity' => $s->severity?->value,
                'category' => $s->category,
                'user_priority' => $s->user_priority,
                'status' => $s->status->value,
                'priority' => $s->priority,
                'submitted_by' => $s->user?->name,
                'team_id' => $s->team_id,
                'created_at' => $s->created_at->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
