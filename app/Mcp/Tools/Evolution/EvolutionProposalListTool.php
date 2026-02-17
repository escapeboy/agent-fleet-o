<?php

namespace App\Mcp\Tools\Evolution;

use App\Domain\Evolution\Models\EvolutionProposal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class EvolutionProposalListTool extends Tool
{
    protected string $name = 'evolution_list';

    protected string $description = 'List evolution proposals for an agent. Returns id, status, analysis, confidence_score, proposed_changes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('Filter by agent ID (required)')
                ->required(),
            'status' => $schema->string()
                ->description('Filter by status: pending, approved, applied, rejected')
                ->enum(['pending', 'approved', 'applied', 'rejected']),
            'limit' => $schema->integer()
                ->description('Max results (default 10, max 50)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = EvolutionProposal::where('agent_id', $request->get('agent_id'))
            ->orderByDesc('created_at');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $limit = min((int) ($request->get('limit', 10)), 50);

        /** @var Collection<int, EvolutionProposal> $proposals */
        $proposals = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $proposals->count(),
            'proposals' => $proposals->map(fn (EvolutionProposal $p) => [
                'id' => $p->id,
                'agent_id' => $p->agent_id,
                'status' => $p->status->value,
                'analysis' => $p->analysis,
                'proposed_changes' => $p->proposed_changes,
                'reasoning' => $p->reasoning,
                'confidence_score' => $p->confidence_score,
                'created_at' => $p->created_at->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
