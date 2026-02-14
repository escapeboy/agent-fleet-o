<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;

class ValidateTaskOutputAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * Have the QA agent validate a task's output.
     *
     * @return array{passed: bool, score: float, feedback: string, issues: array}
     */
    public function execute(CrewTaskExecution $taskExecution, CrewExecution $execution): array
    {
        $config = $execution->config_snapshot;
        $qaAgent = Agent::withoutGlobalScopes()->find($config['qa_agent']['id']);

        if (! $qaAgent) {
            throw new \RuntimeException('QA agent not found.');
        }

        $resolved = $this->providerResolver->resolve(agent: $qaAgent);

        $qualityThreshold = $config['quality_threshold'] ?? 0.70;

        $systemPrompt = "You are {$qaAgent->role}. {$qaAgent->goal}\n\n"
            ."Evaluate the output of a completed task. Check for: accuracy, completeness, relevance to the task description, and quality.\n"
            ."The quality threshold is {$qualityThreshold} (0.0-1.0).\n\n"
            .'Respond with valid JSON: { "passed": bool, "score": float (0.0-1.0), "feedback": string, "issues": [string] }';

        $expectedOutput = $taskExecution->input_context['expected_output'] ?? 'No specific format expected';

        $userPrompt = "Task: {$taskExecution->title}\n"
            ."Description: {$taskExecution->description}\n"
            ."Expected output: {$expectedOutput}\n\n"
            ."Actual output:\n".json_encode($taskExecution->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n\n"
            .'Evaluate this output.';

        $request = new AiRequestDTO(
            provider: $resolved['provider'],
            model: $resolved['model'],
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: 2048,
            teamId: $execution->team_id,
            agentId: $qaAgent->id,
            purpose: 'crew.validate_task',
            temperature: 0.2,
        );

        $response = $this->gateway->complete($request);

        $validation = $this->parseValidation($response->content);

        // Update task with QA results
        $passed = $validation['passed'] && $validation['score'] >= $qualityThreshold;

        $taskExecution->update([
            'qa_feedback' => $validation,
            'qa_score' => $validation['score'],
            'status' => $passed ? CrewTaskStatus::Validated : CrewTaskStatus::NeedsRevision,
        ]);

        // Track cost on execution
        $execution->increment('total_cost_credits', $response->usage->costCredits);

        return $validation;
    }

    private function parseValidation(string $content): array
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*\n?/', '', $content);
            $content = preg_replace('/\n?```\s*$/', '', $content);
        }

        $parsed = json_decode($content, true, 512, JSON_UNESCAPED_UNICODE);

        if (! is_array($parsed) || ! isset($parsed['passed'], $parsed['score'], $parsed['feedback'])) {
            // Fallback: QA response was unparseable, treat as failed
            return [
                'passed' => false,
                'score' => 0.0,
                'feedback' => 'QA agent did not produce a valid validation response.',
                'issues' => ['Invalid QA response format'],
            ];
        }

        return [
            'passed' => (bool) $parsed['passed'],
            'score' => (float) min(1.0, max(0.0, $parsed['score'])),
            'feedback' => (string) $parsed['feedback'],
            'issues' => $parsed['issues'] ?? [],
        ];
    }
}
