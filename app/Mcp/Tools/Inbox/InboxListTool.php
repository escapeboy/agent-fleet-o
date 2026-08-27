<?php

namespace App\Mcp\Tools\Inbox;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Inbox\Models\InboxQueue;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Livewire\Inbox\Services\InboxTriageScorer;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class InboxListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'inbox_list';

    protected string $description = 'Unified triage inbox: everything waiting on a human — pending approvals, human tasks and outbound proposals — each scored and ranked by the same triage model the web inbox uses.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'queue_id' => $schema->string()
                ->description('Restrict to a saved inbox queue (see inbox_queue_list).'),
            'kind' => $schema->string()
                ->description('Filter by item kind.')
                ->enum(['approval', 'human_task', 'proposal']),
            'sort' => $schema->string()
                ->description('score = highest triage score first (default), recent = newest first.')
                ->enum(['score', 'recent'])
                ->default('score'),
            'limit' => $schema->integer()
                ->description('Max results (default 25, max 100).')
                ->default(25),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $allowedKinds = null;
        if ($queueId = $request->get('queue_id')) {
            $queue = InboxQueue::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->find($queueId);

            if (! $queue) {
                return $this->notFoundError('inbox queue');
            }

            $allowedKinds = $queue->allowedKinds() ?: null;
        }

        $scorer = app(InboxTriageScorer::class);
        /** @var list<array<string, mixed>> $items */
        $items = [];

        $approvals = ApprovalRequest::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', ApprovalStatus::Pending)
            ->with('experiment')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        foreach ($approvals as $a) {
            $score = $scorer->scoreApproval($a);
            $rec = $scorer->recommendation($score);
            /** @var array<string, mixed> $context */
            $context = $a->context ?? [];
            /** @var Carbon|null $slaDeadline */
            $slaDeadline = $a->sla_deadline;
            /** @var ApprovalStatus $approvalStatus */
            $approvalStatus = $a->status;
            $items[] = [
                'id' => $a->id,
                'kind' => $a->isHumanTask() ? 'human_task' : 'approval',
                'title' => $context['summary'] ?? $a->experiment?->getAttribute('title') ?? 'Approval request',
                'status' => $approvalStatus->value,
                'created_at' => $a->created_at?->toIso8601String(),
                'sla_deadline' => $slaDeadline?->toIso8601String(),
                'triage_score' => round($score, 3),
                'triage_recommendation' => $rec,
                'triage_label' => $scorer->recommendationLabel($rec),
            ];
        }

        $proposals = OutboundProposal::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', OutboundProposalStatus::PendingApproval)
            ->with('experiment')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        foreach ($proposals as $p) {
            $score = $scorer->scoreProposal($p);
            $rec = $scorer->recommendation($score);
            /** @var array<string, mixed> $target */
            $target = $p->target ?? [];
            $channel = $p->channel->value;
            $items[] = [
                'id' => $p->id,
                'kind' => 'proposal',
                'title' => $channel.' → '.($target['address'] ?? $target['url'] ?? 'unknown'),
                'status' => $p->status->value,
                'created_at' => $p->created_at?->toIso8601String(),
                'sla_deadline' => null,
                'triage_score' => round($score, 3),
                'triage_recommendation' => $rec,
                'triage_label' => $scorer->recommendationLabel($rec),
            ];
        }

        if ($kind = $request->get('kind')) {
            $items = array_values(array_filter($items, fn (array $i): bool => $i['kind'] === $kind));
        }

        if ($allowedKinds !== null) {
            $items = array_values(array_filter($items, fn (array $i): bool => in_array($i['kind'], $allowedKinds, true)));
        }

        usort($items, $request->get('sort', 'score') === 'recent'
            ? fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at'])
            : fn (array $a, array $b): int => $b['triage_score'] <=> $a['triage_score']);

        $limit = min(max((int) $request->get('limit', 25), 1), 100);

        return Response::text(json_encode([
            'count' => count($items),
            'items' => array_slice($items, 0, $limit),
        ]));
    }
}
