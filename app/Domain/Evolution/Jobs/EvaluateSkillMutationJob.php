<?php

namespace App\Domain\Evolution\Jobs;

use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\NotificationService;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateSkillMutationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(private readonly string $proposalId)
    {
        $this->onQueue('ai-calls');
    }

    public function handle(AiGatewayInterface $gateway, NotificationService $notifications): void
    {
        $proposal = EvolutionProposal::withoutGlobalScopes()->find($this->proposalId);
        if (! $proposal || $proposal->status !== EvolutionProposalStatus::Pending) {
            return;
        }

        $skill = Skill::withoutGlobalScopes()->find($proposal->skill_id);
        if (! $skill) {
            return;
        }

        $testCases = SkillExecution::withoutGlobalScopes()
            ->where('skill_id', $skill->id)
            ->whereNotNull('quality_score')
            ->whereNotNull('input')
            ->latest()
            ->take(5)
            ->get();

        if ($testCases->isEmpty()) {
            return;
        }

        $mutatedPrompt = $proposal->proposed_changes['system_prompt'] ?? '';
        $userId = Team::ownerIdFor($proposal->team_id);
        $scores = [];

        foreach ($testCases as $testCase) {
            try {
                $scorePrompt = sprintf(
                    "System prompt to evaluate:\n%s\n\nTest input:\n%s\n\nExpected output quality (reference):\n%s\n\nScore 0.0–1.0 how well this system prompt would handle the input. Return JSON: {\"score\": 0.85, \"reasoning\": \"...\"}",
                    $mutatedPrompt,
                    json_encode($testCase->input ?? []),
                    json_encode($testCase->output ?? []),
                );

                $response = $gateway->complete(new AiRequestDTO(
                    provider: 'anthropic',
                    model: 'claude-haiku-4-5',
                    systemPrompt: 'You are a prompt quality evaluator. Respond with JSON only.',
                    userPrompt: $scorePrompt,
                    maxTokens: 256,
                    teamId: $proposal->team_id,
                    userId: $userId,
                    purpose: 'gepa_evaluation',
                ));

                $result = json_decode($response->content, true);
                $scores[] = (float) ($result['score'] ?? 0.5);
            } catch (\Throwable $e) {
                Log::warning('EvaluateSkillMutationJob: scoring failed', ['proposal_id' => $this->proposalId, 'error' => $e->getMessage()]);
                $scores[] = 0.5;
            }
        }

        $candidateScore = count($scores) > 0 ? array_sum($scores) / count($scores) : 0.0;
        $parentScore = (float) ($proposal->mutation_variant['parent_score'] ?? 0.0);

        $proposal->update([
            'confidence_score' => $candidateScore,
            'mutation_variant' => array_merge($proposal->mutation_variant ?? [], [
                'candidate_score' => $candidateScore,
            ]),
        ]);

        if ($candidateScore > $parentScore + 0.1 && $candidateScore > 0.8) {
            $proposal->update([
                'status' => EvolutionProposalStatus::Applied,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
            ]);

            $skill->update(['system_prompt' => $mutatedPrompt]);

            if ($userId) {
                $delta = round(($candidateScore - $parentScore) * 100);
                $notifications->notify(
                    userId: $userId,
                    teamId: $proposal->team_id,
                    type: 'skill_evolved',
                    title: 'Skill improved via GEPA',
                    body: "Skill \"{$skill->name}\" improved by {$delta}% (score: {$candidateScore}).",
                    actionUrl: '/skills/'.$skill->id,
                );
            }
        }
    }
}
