<?php

namespace App\Mcp\Tools\Evolution;

use App\Domain\Evolution\Enums\EvolutionType;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SkillMutationListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'skill_mutation_list';

    protected string $description = 'List skill mutation evolution proposals for the current team.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()
                ->description('Filter by skill UUID (optional)'),
            'status' => $schema->string()
                ->description('Filter by status: pending, approved, applied, rejected'),
            'limit' => $schema->integer()
                ->description('Max results (default 20)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $query = EvolutionProposal::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('evolution_type', EvolutionType::SkillMutation->value)
            ->latest();

        if ($skillId = $request->get('skill_id')) {
            $query->where('skill_id', $skillId);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $limit = min((int) ($request->get('limit', 20)), 100);
        $proposals = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $proposals->count(),
            'proposals' => $proposals->map(fn ($p) => [
                'id' => $p->id,
                'skill_id' => $p->skill_id,
                'status' => $p->status->value,
                'confidence_score' => $p->confidence_score,
                'mutation_variant' => $p->mutation_variant,
                'reasoning' => $p->reasoning,
                'created_at' => $p->created_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
