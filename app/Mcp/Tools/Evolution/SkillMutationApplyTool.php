<?php

namespace App\Mcp\Tools\Evolution;

use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Enums\EvolutionType;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Skill\Models\Skill;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SkillMutationApplyTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'skill_mutation_apply';

    protected string $description = 'Apply a skill mutation proposal, updating the skill system_prompt immediately.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'proposal_id' => $schema->string()
                ->description('The evolution proposal UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $proposal = EvolutionProposal::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('evolution_type', EvolutionType::SkillMutation->value)
            ->find($request->get('proposal_id'));

        if (! $proposal) {
            return $this->notFoundError('proposal');
        }

        if ($proposal->status === EvolutionProposalStatus::Applied) {
            return Response::text(json_encode(['already_applied' => true, 'proposal_id' => $proposal->id]));
        }

        $skill = Skill::withoutGlobalScopes()->find($proposal->skill_id);
        if (! $skill) {
            return $this->notFoundError('skill');
        }

        $newPrompt = $proposal->proposed_changes['system_prompt'] ?? null;
        if (! $newPrompt) {
            return $this->invalidArgumentError('Proposal has no system_prompt change.');
        }

        $skill->update(['system_prompt' => $newPrompt]);

        $proposal->update([
            'status' => EvolutionProposalStatus::Applied,
            'reviewed_at' => now(),
        ]);

        return Response::text(json_encode([
            'applied' => true,
            'proposal_id' => $proposal->id,
            'skill_id' => $skill->id,
        ]));
    }
}
