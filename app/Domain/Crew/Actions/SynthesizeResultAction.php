<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Models\CrewAgentMessage;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewMember;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;

class SynthesizeResultAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * Have the coordinator synthesize all validated task outputs into a final result.
     *
     * @return array{result: array, cost: int}
     */
    public function execute(CrewExecution $execution): array
    {
        $config = $execution->config_snapshot;
        $coordinator = Agent::withoutGlobalScopes()->find($config['coordinator']['id']);

        if (! $coordinator) {
            throw new \RuntimeException('Coordinator agent not found.');
        }

        $coordinatorMember = \App\Domain\Crew\Models\CrewMember::forAgentInCrew($coordinator->id, $execution->crew_id);
        $resolved = $coordinatorMember
            ? $this->providerResolver->forCrewRole($coordinatorMember)
            : $this->providerResolver->resolve(agent: $coordinator);

        $processType = $config['process_type'] ?? 'parallel';
        $isAdversarial = $processType === 'adversarial';

        if ($isAdversarial) {
            // Build debate transcript from all inter-agent messages
            $messages = CrewAgentMessage::where('crew_execution_id', $execution->id)
                ->orderBy('round')
                ->orderBy('created_at')
                ->get();

            $transcript = $messages->map(fn ($m) => "[Round {$m->round}] {$m->message_type}: {$m->content}")
                ->implode("\n\n");

            $systemPrompt = "You are {$coordinator->role}. {$coordinator->goal}\n\n"
                ."You have facilitated a structured debate between your agents.\n"
                ."Synthesize the debate into a final conclusion.\n"
                .'Output valid JSON with: "result" (the conclusion), "summary" (brief overview), '
                .'"surviving_hypothesis" (the most supported position), "confidence" (0-1 score), "debate_transcript" (condensed version).';

            $userPrompt = "Goal: {$execution->goal}\n\n"
                ."Debate transcript:\n{$transcript}\n\n"
                .'Synthesize into a final conclusion with confidence assessment.';
        } else {
            $validatedOutputs = $execution->taskExecutions()
                ->whereIn('status', ['validated', 'completed'])
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($t) => [
                    'title' => $t->title,
                    'description' => $t->description,
                    'output' => $t->output,
                    'qa_score' => $t->qa_score,
                ])
                ->toArray();

            $systemPrompt = "You are {$coordinator->role}. {$coordinator->goal}\n\n"
                ."You have completed all tasks for the goal below. Now synthesize the individual task outputs into one cohesive final result.\n"
                .'Output valid JSON with a "result" key containing the assembled output and a "summary" key with a brief overview.';

            $taskSummaries = collect($validatedOutputs)
                ->map(fn ($t, $i) => ($i + 1).". {$t['title']}:\n".json_encode($t['output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                ->implode("\n\n");

            $userPrompt = "Goal: {$execution->goal}\n\n"
                ."Completed task outputs:\n{$taskSummaries}\n\n"
                .'Synthesize these into a final result.';
        }

        $userId = $execution->resolveUserId();

        $request = new AiRequestDTO(
            provider: $resolved['provider'],
            model: $resolved['model'],
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: 4096,
            userId: $userId,
            teamId: $execution->team_id,
            agentId: $coordinator->id,
            purpose: 'crew.synthesize_result',
            temperature: 0.3,
        );

        $response = $this->gateway->complete($request);

        $result = $this->parseResult($response->content);
        $totalCost = $response->usage->costCredits;

        // Output reviewer pass: if any crew member has the output_reviewer role, run a review LLM call.
        $outputReviewerMember = CrewMember::withoutGlobalScopes()
            ->where('crew_id', $execution->crew_id)
            ->where('role', CrewMemberRole::OutputReviewer)
            ->first();

        if ($outputReviewerMember) {
            $reviewerAgent = Agent::withoutGlobalScopes()->find($outputReviewerMember->agent_id);

            if ($reviewerAgent) {
                $reviewResolved = $this->providerResolver->forCrewRole($outputReviewerMember);

                $reviewSystem = "You are {$reviewerAgent->role}. Your task is to review the synthesized result for quality, completeness, and accuracy. "
                    .'Output valid JSON with: "approved" (bool), "score" (0-1), "feedback" (string), '
                    .'"revised_result" (the improved result if score < 0.7, otherwise null).';

                $reviewUser = "Original goal: {$execution->goal}\n\n"
                    .'Synthesized result:'."\n".json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    ."\n\nReview this result.";

                $reviewRequest = new AiRequestDTO(
                    provider: $reviewResolved['provider'],
                    model: $reviewResolved['model'],
                    systemPrompt: $reviewSystem,
                    userPrompt: $reviewUser,
                    maxTokens: 2048,
                    userId: $userId,
                    teamId: $execution->team_id,
                    agentId: $reviewerAgent->id,
                    purpose: 'crew.output_review',
                    temperature: 0.2,
                );

                $reviewResponse = $this->gateway->complete($reviewRequest);
                $totalCost += $reviewResponse->usage->costCredits;

                $review = $this->parseResult($reviewResponse->content);

                $approved = (bool) ($review['approved'] ?? true);
                $score = (float) ($review['score'] ?? 1.0);

                if (! $approved && $score < 0.7 && isset($review['revised_result'])) {
                    $result = is_array($review['revised_result'])
                        ? $review['revised_result']
                        : ['result' => $review['revised_result'], 'summary' => 'Revised by output reviewer.'];
                }

                $result['output_review'] = [
                    'approved' => $approved,
                    'score' => $score,
                    'feedback' => $review['feedback'] ?? '',
                    'reviewer_agent_id' => $reviewerAgent->id,
                ];
            }
        }

        return [
            'result' => $result,
            'cost' => $totalCost,
        ];
    }

    private function parseResult(string $content): array
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*\n?/', '', $content);
            $content = preg_replace('/\n?```\s*$/', '', $content);
        }

        $parsed = json_decode($content, true, 512, JSON_UNESCAPED_UNICODE);

        if (is_array($parsed)) {
            return $parsed;
        }

        // Fallback: wrap raw text as the result
        return [
            'result' => $content,
            'summary' => 'Synthesis completed.',
        ];
    }
}
