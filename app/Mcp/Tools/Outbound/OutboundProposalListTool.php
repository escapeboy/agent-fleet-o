<?php

namespace App\Mcp\Tools\Outbound;

use App\Domain\Outbound\Models\OutboundProposal;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class OutboundProposalListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'outbound_proposal_list';

    protected string $description = 'List outbound delivery proposals. Returns status, channel, target, risk_score, a content preview, and the linked experiment. Filterable by status and channel.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: pending_approval, approved, rejected, expired, cancelled'),
            'channel' => $schema->string()
                ->description('Filter by channel: email, webhook, notification, telegram, slack, whatsapp, discord, teams, google_chat, signal_protocol, matrix, supabase_realtime, ntfy'),
            'limit' => $schema->integer()
                ->description('Max proposals to return (1–100, default 25)')
                ->default(25),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $limit = min(max((int) $request->get('limit', 25), 1), 100);

        $query = OutboundProposal::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->with('experiment:id,title')
            ->latest();

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($channel = $request->get('channel')) {
            $query->where('channel', $channel);
        }

        $proposals = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $proposals->count(),
            'proposals' => $proposals->map(function (OutboundProposal $p): array {
                return [
                    'id' => $p->id,
                    'status' => $p->status->value,
                    'channel' => $p->channel->value,
                    'target' => $p->target,
                    'risk_score' => $p->risk_score,
                    'content_preview' => Str::limit((string) json_encode($p->content), 240),
                    'experiment_id' => $p->experiment_id,
                    'experiment_title' => $p->experiment?->title,
                    'created_at' => $p->created_at?->toIso8601String(),
                ];
            })->values()->toArray(),
        ]));
    }
}
