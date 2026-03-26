<?php

namespace App\Domain\Agent\Pipeline\Middleware;

use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Pre-execution scout phase: runs a cheap Haiku call before memory/KG injection
 * to identify what specific knowledge the agent will need for this task.
 *
 * The resulting queries are stored on the context and consumed by InjectMemoryContext
 * and InjectKnowledgeGraphContext to perform targeted retrieval instead of generic
 * semantic search against the raw input text.
 *
 * Enable per-agent via agent.config['enable_scout_phase'] = true,
 * or globally via config('agent.scout_phase.enabled').
 */
class PreExecutionScout
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    public function handle(AgentExecutionContext $ctx, Closure $next): AgentExecutionContext
    {
        if (! $this->isEnabled($ctx)) {
            return $next($ctx);
        }

        $inputText = $this->extractInputText($ctx->input);

        if (mb_strlen(trim($inputText)) < 20) {
            return $next($ctx);
        }

        try {
            $queries = $this->runScout($ctx, $inputText);

            if (! empty($queries)) {
                $ctx->scoutQueries = $queries;
            }
        } catch (\Throwable $e) {
            Log::warning('PreExecutionScout: scout phase failed, continuing without targeted queries', [
                'agent_id' => $ctx->agent->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $next($ctx);
    }

    /**
     * Run a single cheap Haiku call to identify what the agent needs to know.
     *
     * @return array<string>
     */
    private function runScout(AgentExecutionContext $ctx, string $inputText): array
    {
        $systemPrompt = 'You are a planning assistant. Given a task description, identify the specific knowledge needed to perform it well. '
            .'Return 3-5 targeted search queries whose answers would make an AI agent most effective at this task. '
            .'Be specific (e.g. "client X budget constraints" not "context"). '
            .'Return only valid JSON with a single key: {"queries": ["query1", "query2", ...]}';

        $request = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            systemPrompt: $systemPrompt,
            userPrompt: "Task:\n{$inputText}",
            maxTokens: 256,
            teamId: $ctx->teamId,
            agentId: $ctx->agent->id,
            purpose: 'agent.scout_phase',
            temperature: 0.3,
        );

        $response = $this->gateway->complete($request);

        return $this->parseQueries($response->content);
    }

    /**
     * @return array<string>
     */
    private function parseQueries(string $content): array
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*\n?/', '', $content);
            $content = preg_replace('/\n?```\s*$/', '', $content);
        }

        $parsed = json_decode($content, true, 512, JSON_UNESCAPED_UNICODE);

        if (! is_array($parsed) || ! isset($parsed['queries']) || ! is_array($parsed['queries'])) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $parsed['queries']),
            fn (string $q) => mb_strlen(trim($q)) >= 5,
        ));
    }

    private function extractInputText(array $input): string
    {
        return $input['task'] ?? $input['content'] ?? $input['query'] ?? implode(' ', array_filter($input, 'is_string'));
    }

    private function isEnabled(AgentExecutionContext $ctx): bool
    {
        // Per-agent opt-in takes precedence
        if (isset($ctx->agent->config['enable_scout_phase'])) {
            return (bool) $ctx->agent->config['enable_scout_phase'];
        }

        return (bool) config('agent.scout_phase.enabled', false);
    }
}
