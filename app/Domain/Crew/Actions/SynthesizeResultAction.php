<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\CrewExecution;
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

        $resolved = $this->providerResolver->resolve(agent: $coordinator);

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
            ."Output valid JSON with a \"result\" key containing the assembled output and a \"summary\" key with a brief overview.";

        $taskSummaries = collect($validatedOutputs)
            ->map(fn ($t, $i) => ($i + 1).". {$t['title']}:\n".json_encode($t['output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
            ->implode("\n\n");

        $userPrompt = "Goal: {$execution->goal}\n\n"
            ."Completed task outputs:\n{$taskSummaries}\n\n"
            ."Synthesize these into a final result.";

        $request = new AiRequestDTO(
            provider: $resolved['provider'],
            model: $resolved['model'],
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: 4096,
            teamId: $execution->team_id,
            agentId: $coordinator->id,
            purpose: 'crew.synthesize_result',
            temperature: 0.3,
        );

        $response = $this->gateway->complete($request);

        $result = $this->parseResult($response->content);

        return [
            'result' => $result,
            'cost' => $response->usage->costCredits,
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
