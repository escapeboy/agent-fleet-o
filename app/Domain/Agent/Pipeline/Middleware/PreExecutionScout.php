<?php

namespace App\Domain\Agent\Pipeline\Middleware;

use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Pre-execution scout phase: runs a cheap lightweight LLM call before memory/KG
 * injection to identify what specific knowledge the agent will need for this task.
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
    /** Cheapest available model per cloud provider for the scout call */
    private const SCOUT_MODELS = [
        'anthropic' => 'claude-haiku-4-5-20251001',
        'openai' => 'gpt-4o-mini',
        'google' => 'gemini-2.5-flash',
    ];

    /** Maximum characters per scout query to prevent prompt injection amplification */
    private const MAX_QUERY_LENGTH = 200;

    /** Maximum number of queries to retain */
    private const MAX_QUERIES = 5;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
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
     * Run a single cheap LLM call to identify what the agent needs to know.
     *
     * Uses the team's configured provider (BYOK hierarchy) so the scout call
     * respects the same credential resolution as regular agent calls.
     *
     * @return array<string>
     */
    private function runScout(AgentExecutionContext $ctx, string $inputText): array
    {
        $team = Team::find($ctx->teamId);
        $resolved = $this->providerResolver->resolve(agent: $ctx->agent, team: $team);

        // Only cloud providers support the structured JSON scout call.
        // Local agents (codex, claude-code) are skipped — fall through gracefully.
        $scoutModel = self::SCOUT_MODELS[$resolved['provider']] ?? null;

        if ($scoutModel === null) {
            return [];
        }

        $systemPrompt = 'You are a planning assistant. Given a task description, identify the specific knowledge needed to perform it well. '
            .'Return 3-5 targeted search queries whose answers would make an AI agent most effective at this task. '
            .'Be specific (e.g. "client X budget constraints" not "context"). '
            .'Return only valid JSON with a single key: {"queries": ["query1", "query2", ...]}';

        $request = new AiRequestDTO(
            provider: $resolved['provider'],
            model: $scoutModel,
            systemPrompt: $systemPrompt,
            userPrompt: "Task:\n".mb_substr($inputText, 0, 2000),
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
     * Parse and sanitise the LLM response into a list of safe search queries.
     *
     * Enforces length and count caps to prevent prompt-injection amplification
     * when queries are prepended to embedding search inputs.
     *
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

        $queries = array_values(array_filter(
            array_map(fn ($q) => mb_substr(trim((string) $q), 0, self::MAX_QUERY_LENGTH), $parsed['queries']),
            fn (string $q) => mb_strlen($q) >= 5,
        ));

        return array_slice($queries, 0, self::MAX_QUERIES);
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

        // Per-team override
        $team = Team::withoutGlobalScopes()->find($ctx->agent->team_id);
        if ($team && isset($team->settings['scout_phase_enabled'])) {
            return (bool) $team->settings['scout_phase_enabled'];
        }

        return (bool) config('agent.scout_phase.enabled', false);
    }
}
