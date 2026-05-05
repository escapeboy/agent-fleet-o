<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Widget\Concerns\ResolvesWidgetAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BugReportListController extends Controller
{
    use ResolvesWidgetAccess;

    private const STATUS_GROUPS = [
        'received' => 'not_started',
        'triaged' => 'not_started',
        'in_progress' => 'in_progress',
        'delegated_to_agent' => 'in_progress',
        'agent_fixing' => 'in_progress',
        'review' => 'in_progress',
        'resolved' => 'done',
        'dismissed' => 'done',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'team_public_key' => ['required', 'string'],
            'reporter_id' => ['required', 'string', 'max:255'],
            'project' => ['nullable', 'string', 'max:100'],
        ]);

        $team = $this->resolveTeam($validated['team_public_key']);

        $this->throttle('widget-bug-reports-list:'.$team->id.':'.$validated['reporter_id'], 20);

        $signals = Signal::query()
            ->where('team_id', $team->id)
            ->where('source_type', 'bug_report')
            ->where('payload->reporter_id', $validated['reporter_id'])
            ->when($validated['project'] ?? null, function ($query, $project) {
                $query->where('payload->project', $project);
            })
            ->withCount(['comments as visible_comments_count' => function ($query) {
                $query->where('widget_visible', true)
                    ->where('author_type', '!=', CommentAuthorType::Reporter->value);
            }])
            ->latest()
            ->limit(50)
            ->get(['id', 'payload', 'status', 'created_at']);

        $reports = $signals->map(function (Signal $signal) {
            $status = $signal->status instanceof SignalStatus
                ? $signal->status->value
                : (string) $signal->status;

            $payload = is_array($signal->payload) ? $signal->payload : [];

            return [
                'id' => $signal->id,
                'title' => $payload['title'] ?? null,
                'description' => $payload['description'] ?? null,
                'url' => $payload['url'] ?? null,
                'severity' => $payload['severity'] ?? null,
                'status' => $status,
                'status_group' => self::STATUS_GROUPS[$status] ?? 'not_started',
                'created_at' => $signal->created_at?->toISOString(),
                'unread_comments_count' => (int) ($signal->visible_comments_count ?? 0),
            ];
        });

        return $this->withCors(response()->json(['reports' => $reports]));
    }
}
