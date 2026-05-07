<?php

namespace App\Infrastructure\AI\Gateways;

use App\Domain\Budget\Services\CostCalculator;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Shared\Services\SsrfGuard;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\Encryption\CredentialEncryption;
use App\Infrastructure\Telemetry\TracerProvider as FleetTracerProvider;
use Closure;
use Illuminate\Support\Collection;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

class PrismAiGateway implements AiGatewayInterface
{
    /** @var list<AiMiddlewareInterface> */
    private array $middleware = [];

    public function __construct(
        private readonly CostCalculator $costCalculator,
    ) {}

    /** @param  list<AiMiddlewareInterface>  $middleware */
    public function withMiddleware(array $middleware): static
    {
        $clone = clone $this;
        $clone->middleware = $middleware;

        return $clone;
    }

    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        $request = $this->normalizeCustomEndpoint($request);

        if ($request->teamId) {
            app()->instance('ai.current_team_id', $request->teamId);
        }

        if ($request->experimentId) {
            app()->instance('ai.current_experiment_id', $request->experimentId);
        }

        if ($request->agentId) {
            app()->instance('ai.current_agent_id', $request->agentId);
        }

        $tracer = app(FleetTracerProvider::class)->tracer('fleetq.llm');
        $span = $tracer->spanBuilder('llm.provider.'.$request->provider)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('llm.provider', $request->provider)
            ->setAttribute('llm.model', $request->model)
            ->setAttribute('llm.operation', 'complete')
            ->setAttribute('llm.max_tokens', $request->maxTokens)
            ->setAttribute('llm.has_tools', $request->hasTools())
            ->startSpan();
        $scope = $span->activate();

        $pipeline = $this->buildPipeline(fn (AiRequestDTO $req) => $this->executeRequest($req));

        try {
            $response = $pipeline($request);

            $span->setAttribute('llm.usage.input_tokens', $response->usage->promptTokens);
            $span->setAttribute('llm.usage.output_tokens', $response->usage->completionTokens);
            $span->setAttribute('llm.usage.total_tokens', $response->usage->totalTokens);
            $span->setAttribute('llm.latency_ms', $response->latencyMs);
            $span->setStatus(StatusCode::STATUS_OK);

            return $response;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();

            // Clear per-call context bindings so stale values don't leak across
            // Horizon jobs that share the same worker process/container instance.
            app()->forgetInstance('ai.current_experiment_id');
            app()->forgetInstance('ai.current_agent_id');
        }
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        $request = $this->normalizeCustomEndpoint($request);

        // Structured output doesn't support streaming — fall back
        if ($request->isStructured()) {
            return $this->complete($request);
        }

        if ($request->teamId) {
            app()->instance('ai.current_team_id', $request->teamId);
        }

        $tracer = app(FleetTracerProvider::class)->tracer('fleetq.llm');
        $span = $tracer->spanBuilder('llm.provider.'.$request->provider)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('llm.provider', $request->provider)
            ->setAttribute('llm.model', $request->model)
            ->setAttribute('llm.operation', 'stream')
            ->setAttribute('llm.max_tokens', $request->maxTokens)
            ->setAttribute('llm.has_tools', $request->hasTools())
            ->startSpan();
        $scope = $span->activate();

        try {
            // Tool calling: run tool loop via complete(), then stream the final text.
            // This gives progressive visibility into tool calls while streaming the answer.
            if ($request->hasTools()) {
                $pipeline = $this->buildPipeline(fn (AiRequestDTO $req) => $this->executeToolThenStreamRequest($req, $onChunk));
                $response = $pipeline($request);
            } else {
                $pipeline = $this->buildPipeline(fn (AiRequestDTO $req) => $this->executeStreamRequest($req, $onChunk));
                $response = $pipeline($request);
            }

            $span->setAttribute('llm.usage.input_tokens', $response->usage->promptTokens);
            $span->setAttribute('llm.usage.output_tokens', $response->usage->completionTokens);
            $span->setAttribute('llm.usage.total_tokens', $response->usage->totalTokens);
            $span->setAttribute('llm.latency_ms', $response->latencyMs);
            $span->setStatus(StatusCode::STATUS_OK);

            return $response;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        return $this->costCalculator->estimateCost(
            provider: $request->provider,
            model: $request->model,
            maxTokens: $request->maxTokens,
        );
    }

    private function executeStreamRequest(AiRequestDTO $request, ?callable $onChunk): AiResponseDTO
    {
        $provider = $this->resolveProvider($request->provider);
        $this->applyTeamCredentials($request);
        $localConfig = $this->getPerRequestProviderConfig($request);
        $startTime = hrtime(true);

        $generator = Prism::text()
            ->using($provider, $request->model, $localConfig)
            ->withSystemPrompt($request->systemPrompt)
            ->withPrompt($request->userPrompt)
            ->withMaxTokens($request->maxTokens)
            ->usingTemperature($request->temperature)
            ->withClientOptions(['timeout' => 120])
            ->asStream();

        $accumulated = '';
        $promptTokens = 0;
        $completionTokens = 0;

        foreach ($generator as $event) {
            if ($event instanceof TextDeltaEvent) {
                $accumulated .= $event->delta;

                if ($onChunk) {
                    $onChunk($event->delta);
                }
            } elseif ($event instanceof StreamEndEvent && $event->usage) {
                $promptTokens = $event->usage->promptTokens;
                $completionTokens = $event->usage->completionTokens;
            }
        }

        $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        // Estimate tokens if usage wasn't reported by the stream
        if ($completionTokens === 0 && $accumulated !== '') {
            $completionTokens = (int) ceil(strlen($accumulated) / 4);
        }

        $cacheStrategy = $this->resolveCacheStrategy($request);

        return new AiResponseDTO(
            content: $accumulated,
            parsedOutput: null,
            usage: new AiUsageDTO(
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                costCredits: $this->costCalculator->calculateCost(
                    provider: $request->provider,
                    model: $request->model,
                    inputTokens: $promptTokens,
                    outputTokens: $completionTokens,
                    cachedInputTokens: 0,
                    cacheStrategy: $cacheStrategy,
                ),
                cachedInputTokens: 0,
                cacheStrategy: $cacheStrategy,
            ),
            provider: $request->provider,
            model: $request->model,
            latencyMs: $latencyMs,
        );
    }

    /**
     * Hybrid tool+stream: wraps tool closures to emit progress on each tool call,
     * then uses PrismPHP's internal multi-step loop. Final text is chunked to onChunk.
     *
     * PrismPHP handles multi-step tool calling internally (recursive handle() calls).
     * We can't hook between steps, but we CAN wrap each tool's callable to emit
     * a progress event the moment PrismPHP invokes the tool — giving real-time
     * visibility into which tools are being called.
     */
    private function executeToolThenStreamRequest(AiRequestDTO $request, ?callable $onChunk): AiResponseDTO
    {
        $provider = $this->resolveProvider($request->provider);
        $this->applyTeamCredentials($request);
        $localConfig = $this->getPerRequestProviderConfig($request);
        $startTime = hrtime(true);

        $systemPromptArg = ($request->enablePromptCaching && $request->provider === 'anthropic')
            ? (new SystemMessage($request->systemPrompt))->withProviderOptions(['cacheType' => 'ephemeral'])
            : $request->systemPrompt;

        $tools = $request->tools;
        if ($request->enablePromptCaching && $request->provider === 'anthropic' && count($tools) > 0) {
            $lastIndex = count($tools) - 1;
            $tools[$lastIndex] = (clone $tools[$lastIndex])->withProviderOptions(['cacheType' => 'ephemeral']);
        }

        $builder = Prism::text()
            ->using($provider, $request->model, $localConfig)
            ->withSystemPrompt($systemPromptArg)
            ->withPrompt($request->userPrompt)
            ->withMaxTokens($request->maxTokens)
            ->usingTemperature($request->temperature)
            ->withClientOptions(['timeout' => 120])
            ->withClientRetry(2, 500)
            ->withTools($tools)
            ->withMaxSteps($request->maxSteps);

        if ($request->toolChoice !== null) {
            $toolChoice = match ($request->toolChoice) {
                'any' => ToolChoice::Any,
                'auto' => ToolChoice::Auto,
                'none' => ToolChoice::None,
                default => $request->toolChoice,
            };
            $builder->withToolChoice($toolChoice);
        }

        $response = $builder->asText();

        // Extract tool results from completed steps
        $toolResults = null;
        $toolCallsCount = 0;
        $stepsCount = 0;
        $reasoningChain = null;

        if ($response->steps->isNotEmpty()) {
            $stepsCount = $response->steps->count();
            $toolResultsList = [];
            $reasoningSteps = [];

            foreach ($response->steps as $stepIndex => $step) {
                foreach ($step->toolCalls as $toolCall) {
                    $toolResultsList[] = [
                        'toolName' => $toolCall->name,
                        'args' => $toolCall->arguments(),
                    ];
                    $reasoningSteps[] = [
                        'step' => $stepIndex + 1,
                        'thought' => "Calling tool: {$toolCall->name}",
                        'action' => $toolCall->name,
                        'result' => $toolCall->arguments(),
                    ];
                }
            }

            $toolCallsCount = count($toolResultsList);
            $toolResults = $toolCallsCount > 0 ? $toolResultsList : null;

            if (! empty($reasoningSteps)) {
                $reasoningChain = $reasoningSteps;
            }
        }

        $text = $response->text;

        // Unwrap OpenRouter/OpenAI JSON wrapping
        if ($text !== '' && str_starts_with(ltrim($text), '{')) {
            $decoded = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['text']) && count($decoded) === 1) {
                $text = $decoded['text'];
            }
        }

        // Emit the final text via onChunk in chunks for progressive display
        if ($onChunk && $text !== '') {
            $chunks = str_split($text, 80);
            foreach ($chunks as $chunk) {
                $onChunk($chunk, 'text_delta');
            }
        }

        $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return new AiResponseDTO(
            content: $text,
            parsedOutput: null,
            usage: $this->buildUsageDTO($response->usage, $request),
            provider: $request->provider,
            model: $request->model,
            latencyMs: $latencyMs,
            toolResults: $toolResults,
            toolCallsCount: $toolCallsCount,
            stepsCount: $stepsCount,
            reasoningChain: $reasoningChain,
        );
    }

    private function executeRequest(AiRequestDTO $request): AiResponseDTO
    {
        $provider = $this->resolveProvider($request->provider);
        $this->applyTeamCredentials($request);
        $localConfig = $this->getPerRequestProviderConfig($request);
        $startTime = hrtime(true);

        if ($request->isStructured()) {
            $response = Prism::structured()
                ->using($provider, $request->model, $localConfig)
                ->withSystemPrompt($request->systemPrompt)
                ->withPrompt($request->userPrompt)
                ->withMaxTokens($request->maxTokens)
                ->usingTemperature($request->temperature)
                ->withSchema($request->outputSchema)
                ->withClientOptions(['timeout' => 120])
                ->withClientRetry(2, 500)
                ->asStructured();

            $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return new AiResponseDTO(
                content: json_encode($response->structured),
                parsedOutput: $response->structured,
                usage: $this->buildUsageDTO($response->usage, $request),
                provider: $request->provider,
                model: $request->model,
                latencyMs: $latencyMs,
                schemaValid: true,
            );
        }

        // Build system prompt — wrap in SystemMessage with cache_control when caching is enabled (Anthropic only)
        $systemPromptArg = ($request->enablePromptCaching && $request->provider === 'anthropic')
            ? (new SystemMessage($request->systemPrompt))->withProviderOptions(['cacheType' => 'ephemeral'])
            : $request->systemPrompt;

        $builder = Prism::text()
            ->using($provider, $request->model, $localConfig)
            ->withSystemPrompt($systemPromptArg)
            ->withPrompt($request->userPrompt)
            ->withMaxTokens($request->maxTokens)
            ->usingTemperature($request->temperature)
            ->withClientOptions(['timeout' => 120])
            ->withClientRetry(2, 500);

        // Extended thinking (Anthropic-only): explicit budget takes precedence; otherwise derive from effort level.
        // Cap at High effort maximum (32K) to prevent runaway costs from uncapped caller-supplied values.
        $effectiveBudget = $request->thinkingBudget
            ?? ($request->effort !== null ? $request->effort->toBudgetTokens() : null);

        if ($effectiveBudget !== null) {
            $effectiveBudget = min($effectiveBudget, 32_000);
        }

        if ($effectiveBudget !== null && $effectiveBudget > 0 && $request->provider === 'anthropic') {
            $builder->withProviderOptions([
                'thinking' => [
                    'enabled' => true,
                    'budgetTokens' => $effectiveBudget,
                ],
            ]);
        }

        // Add tool support when tools are provided
        if ($request->hasTools()) {
            $tools = $request->tools;

            // Mark the last tool with cache_control so Anthropic caches the entire tools block (Anthropic only)
            if ($request->enablePromptCaching && $request->provider === 'anthropic' && count($tools) > 0) {
                $lastIndex = count($tools) - 1;
                $tools[$lastIndex] = (clone $tools[$lastIndex])->withProviderOptions(['cacheType' => 'ephemeral']);
            }

            $builder->withTools($tools)
                ->withMaxSteps($request->maxSteps);

            if ($request->toolChoice !== null) {
                // Map string values to ToolChoice enum so all providers get the correct type.
                // Passing a raw string to PrismPHP is treated as a specific function name,
                // not as a mode like 'any'/'auto'/'none'.
                $toolChoice = match ($request->toolChoice) {
                    'any' => ToolChoice::Any,
                    'auto' => ToolChoice::Auto,
                    'none' => ToolChoice::None,
                    default => $request->toolChoice, // specific tool name — pass as-is
                };
                $builder->withToolChoice($toolChoice);
            }
        }

        $response = $builder->asText();

        $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        // Extract tool results from multi-step response
        $toolResults = null;
        $stepsData = null;
        $toolCallsCount = 0;
        $stepsCount = 0;

        $reasoningChain = null;

        if ($request->hasTools() && $response->steps->isNotEmpty()) {
            $stepsCount = $response->steps->count();
            $toolResultsList = [];
            $reasoningSteps = [];

            foreach ($response->steps as $stepIndex => $step) {
                foreach ($step->toolCalls as $toolCall) {
                    $toolResultsList[] = [
                        'toolName' => $toolCall->name,
                        'args' => $toolCall->arguments(),
                    ];
                    $reasoningSteps[] = [
                        'step' => $stepIndex + 1,
                        'thought' => "Calling tool: {$toolCall->name}",
                        'action' => $toolCall->name,
                        'result' => $toolCall->arguments(),
                    ];
                }
            }

            $toolCallsCount = count($toolResultsList);
            $toolResults = $toolCallsCount > 0 ? $toolResultsList : null;

            if (! empty($reasoningSteps)) {
                $reasoningChain = $reasoningSteps;
            }
        }

        // Analyse tool call sequences for semantic repetition (DeerFlow-inspired).
        // Detects when the agent calls the same set of tools with the same arguments
        // across multiple steps — a sign it's stuck in a loop rather than making progress.
        $loopAnalysis = $request->hasTools() && $response->steps->count() > 2
            ? $this->analyseToolCallRepetition($response->steps)
            : null;

        // Some OpenRouter/OpenAI-compatible models wrap plain text in {"text": "..."}
        // when they detect tool schemas in the prompt. Unwrap to get clean content.
        $text = $response->text;
        if ($text !== '' && str_starts_with(ltrim($text), '{')) {
            $decoded = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['text']) && count($decoded) === 1) {
                $text = $decoded['text'];
            }
        }
        if (str_contains($text, '<thinking>')) {
            preg_match_all('/<thinking>(.*?)<\/thinking>/s', $text, $matches);
            if (! empty($matches[1])) {
                $existingSteps = $reasoningChain ?? [];
                $thinkingSteps = array_map(fn ($thought, $i) => [
                    'step' => count($existingSteps) + $i + 1,
                    'thought' => trim($thought),
                    'action' => 'thinking',
                    'result' => null,
                ], $matches[1], array_keys($matches[1]));
                $reasoningChain = array_merge($thinkingSteps, $existingSteps);
            }
        }

        return new AiResponseDTO(
            content: $text,
            parsedOutput: null,
            usage: $this->buildUsageDTO($response->usage, $request),
            provider: $request->provider,
            model: $request->model,
            latencyMs: $latencyMs,
            toolResults: $toolResults,
            toolCallsCount: $toolCallsCount,
            stepsCount: $stepsCount,
            reasoningChain: $reasoningChain,
            loopAnalysis: $loopAnalysis,
        );
    }

    /**
     * Detect repeated identical tool call sequences across steps.
     *
     * Build the usage DTO with cache info extracted from Prism's Usage value object.
     *
     * cacheReadInputTokens is exposed by Anthropic, OpenAI, Gemini, and OpenRouter
     * provider handlers when prompt caching is engaged. cacheStrategy is derived
     * from the request flag — Anthropic uses ephemeral_5m by default in our code path.
     */
    private function buildUsageDTO(Usage $usage, AiRequestDTO $request): AiUsageDTO
    {
        $cachedInputTokens = (int) ($usage->cacheReadInputTokens ?? 0);
        $cacheStrategy = $this->resolveCacheStrategy($request);

        return new AiUsageDTO(
            promptTokens: $usage->promptTokens,
            completionTokens: $usage->completionTokens,
            costCredits: $this->costCalculator->calculateCost(
                provider: $request->provider,
                model: $request->model,
                inputTokens: $usage->promptTokens,
                outputTokens: $usage->completionTokens,
                cachedInputTokens: $cachedInputTokens,
                cacheStrategy: $cacheStrategy,
            ),
            cachedInputTokens: $cachedInputTokens,
            cacheStrategy: $cacheStrategy,
        );
    }

    /**
     * Map the request's prompt-caching flag + provider to a CostCalculator cache strategy.
     * Returns null when caching is disabled or the provider doesn't engage write surcharges
     * in our current code path (gateway only injects cache_control for Anthropic).
     */
    private function resolveCacheStrategy(AiRequestDTO $request): ?string
    {
        if (! $request->enablePromptCaching) {
            return null;
        }

        // Today the gateway injects cacheType=ephemeral on Anthropic system prompts + last tool.
        // Other providers consume the cached_tokens value but don't incur write surcharge here.
        if ($request->provider === 'anthropic') {
            return CostCalculator::CACHE_STRATEGY_5M;
        }

        return null;
    }

    /**
     * Each step's tool calls are serialised (sorted name+args) and hashed with MD5.
     * Returns the maximum repeat count for any single hash and the full distribution.
     *
     * @param  Collection<int, AssistantMessage>  $steps
     * @return array{max_repeat: int, distribution: array<string, int>}
     */
    private function analyseToolCallRepetition(Collection $steps): array
    {
        $hashCounts = [];

        foreach ($steps as $step) {
            if (empty($step->toolCalls)) {
                continue;
            }

            $calls = collect($step->toolCalls)
                ->map(function ($tc) {
                    $args = $tc->arguments();
                    ksort($args);
                    $encoded = json_encode($args);
                    // Bound per-call serialization to prevent DoS via oversized payloads
                    if (strlen($encoded) > 4096) {
                        $encoded = substr($encoded, 0, 4096);
                    }

                    return $tc->name.':'.$encoded;
                })
                ->sort()
                ->values()
                ->implode('|');

            $hash = md5($calls);
            $hashCounts[$hash] = ($hashCounts[$hash] ?? 0) + 1;
        }

        if (empty($hashCounts)) {
            return ['max_repeat' => 0, 'distribution' => []];
        }

        return [
            'max_repeat' => max($hashCounts),
            'distribution' => $hashCounts,
        ];
    }

    /**
     * If the request has a teamId, look up team-specific API credentials
     * and inject them into Prism's runtime config. Falls back to platform config.
     * Local HTTP providers (ollama, openai_compatible) use per-request config instead.
     *
     * IMPORTANT: Horizon workers are long-lived processes. config() mutations persist
     * across jobs. This method ALWAYS explicitly sets the config key — to the team key
     * when a BYOK credential exists, or to the platform key otherwise — to prevent
     * a team's API key from leaking into subsequent jobs that use platform fallback.
     */
    private function applyTeamCredentials(AiRequestDTO $request): void
    {
        if (in_array($request->provider, ['ollama', 'openai_compatible', 'litellm_proxy', 'custom_endpoint'], true)) {
            app()->instance('ai.byok_source', null);

            return; // Handled via getPerRequestProviderConfig()
        }

        $configKey = match ($request->provider) {
            'anthropic' => 'prism.providers.anthropic.api_key',
            'openai' => 'prism.providers.openai.api_key',
            'google' => 'prism.providers.gemini.api_key',
            'groq' => 'prism.providers.groq.api_key',
            'openrouter' => 'prism.providers.openrouter.api_key',
            'mistral' => 'prism.providers.mistral.api_key',
            'deepseek' => 'prism.providers.deepseek.api_key',
            'xai' => 'prism.providers.xai.api_key',
            'perplexity' => 'prism.providers.perplexity.api_key',
            'fireworks' => 'prism.providers.fireworks.api_key',
            default => null,
        };

        // Per-request BYOK override — used by Partner Program / finance sub-program flows.
        // Wins over TeamProviderCredential lookup. Override key is NEVER persisted.
        if ($request->providerCredentialOverride !== null && $request->providerCredentialOverride !== '' && $configKey) {
            config([$configKey => $request->providerCredentialOverride]);
            app()->instance('ai.byok_source', 'request_override');

            return;
        }

        if (! $request->teamId) {
            app()->instance('ai.byok_source', $configKey ? 'platform' : null);

            return;
        }

        $credential = TeamProviderCredential::where('team_id', $request->teamId)
            ->where('provider', $request->provider)
            ->where('is_active', true)
            ->first();

        if ($credential && $configKey && isset($credential->credentials['api_key'])) {
            CredentialEncryption::logAccess(
                $request->teamId,
                'team_provider_credential',
                $credential->id,
                'ai_gateway_call',
                extra: ['provider' => $request->provider, 'model' => $request->model],
            );

            config([$configKey => $credential->credentials['api_key']]);
            app()->instance('ai.byok_source', 'team');

            return;
        }

        // No team credential — check if team's plan allows platform fallback.
        $team = Team::find($request->teamId);
        if ($team && ! $team->hasFeature('platform_llm_fallback')) {
            throw new RuntimeException(
                'Your plan does not include platform AI keys. '
                .'Please add your own API key in Team Settings, or upgrade your plan.',
            );
        }

        // Explicitly restore the original platform API key to clear any
        // BYOK key set by a prior Horizon job.
        if ($configKey) {
            $platformKey = config("services.platform_api_keys.{$request->provider}");
            config([$configKey => $platformKey]);
        }

        app()->instance('ai.byok_source', $configKey ? 'platform' : null);
    }

    private function resolveProvider(string $provider): Provider|string
    {
        return match ($provider) {
            'anthropic' => Provider::Anthropic,
            'openai' => Provider::OpenAI,
            'google' => Provider::Gemini,
            'groq' => Provider::Groq,
            'openrouter' => Provider::OpenRouter,
            'ollama' => Provider::Ollama,
            'mistral' => Provider::Mistral,
            'deepseek' => Provider::DeepSeek,
            'xai' => Provider::XAI,
            'perplexity' => 'perplexity',
            'fireworks' => 'fireworks',
            'openai_compatible' => 'openai_compatible',
            'custom_endpoint' => 'custom_endpoint',
            'litellm_proxy' => 'litellm_proxy',
            default => throw new PrismException("Unsupported provider: {$provider}"),
        };
    }

    /**
     * For providers that use per-request config (ollama, openai_compatible,
     * custom_endpoint), resolve the base URL and optional API key from
     * team credentials and return as per-request config array.
     *
     * @return array<string, string>
     */
    private function getPerRequestProviderConfig(AiRequestDTO $request): array
    {
        // Custom AI endpoints — resolve by team + provider + name
        if ($request->provider === 'custom_endpoint') {
            if (! $request->teamId || ! $request->providerName) {
                throw new RuntimeException(
                    'Custom endpoint requires both teamId and providerName.',
                );
            }

            $credential = TeamProviderCredential::where('team_id', $request->teamId)
                ->where('provider', 'custom_endpoint')
                ->where('name', $request->providerName)
                ->where('is_active', true)
                ->first();

            if (! $credential) {
                throw new RuntimeException(
                    "Custom endpoint '{$request->providerName}' not found or inactive.",
                );
            }

            $creds = $credential->credentials;
            $baseUrl = rtrim($creds['base_url'] ?? '', '/').'/v1/';

            // SSRF protection: block private/internal IPs in cloud mode
            app(SsrfGuard::class)->assertPublicUrl($baseUrl);

            return [
                'url' => $baseUrl,
                'api_key' => $creds['api_key'] ?? '',
            ];
        }

        // Anthropic Fast Mode — per-request beta header. Merged as the provider
        // config 3rd arg to Prism::text()->using() → PrismManager forwards it to
        // the Anthropic provider constructor which sets the anthropic-beta header.
        // CRLF is stripped to prevent HTTP response splitting if the env value is
        // ever misconfigured with stray line terminators.
        if ($request->provider === 'anthropic' && $this->isEffectiveFastMode($request)) {
            $betaId = preg_replace('/[\r\n]/', '', (string) config('ai_routing.fast_mode.beta_identifier'));

            return ['anthropic_beta' => $betaId];
        }

        // Local HTTP providers (ollama, openai_compatible, litellm_proxy)
        if (! in_array($request->provider, ['ollama', 'openai_compatible', 'litellm_proxy'], true)) {
            return [];
        }

        $defaultUrl = config("llm_providers.{$request->provider}.default_url", 'http://localhost:11434');

        $defaults = [
            'url' => $defaultUrl,
            'api_key' => '',
        ];

        if (! $request->teamId) {
            return $defaults;
        }

        $credential = TeamProviderCredential::where('team_id', $request->teamId)
            ->where('provider', $request->provider)
            ->where('is_active', true)
            ->first();

        if (! $credential) {
            return $defaults;
        }

        $creds = $credential->credentials;

        return [
            'url' => $creds['base_url'] ?? $defaultUrl,
            'api_key' => $creds['api_key'] ?? '',
        ];
    }

    /**
     * If provider is a compound key like "custom_endpoint:my-proxy",
     * split it into provider='custom_endpoint' and providerName='my-proxy'.
     * This allows agents/skills to store a single provider string.
     */
    private function normalizeCustomEndpoint(AiRequestDTO $request): AiRequestDTO
    {
        // Validate early so the middleware pipeline never runs with null teamId
        if ($request->provider === 'custom_endpoint' && (! $request->teamId || ! $request->providerName)) {
            throw new RuntimeException('Custom endpoint requires both teamId and providerName.');
        }

        if (! str_starts_with($request->provider, 'custom_endpoint:')) {
            return $request;
        }

        $name = substr($request->provider, strlen('custom_endpoint:'));

        return new AiRequestDTO(
            provider: 'custom_endpoint',
            model: $request->model,
            systemPrompt: $request->systemPrompt,
            userPrompt: $request->userPrompt,
            maxTokens: $request->maxTokens,
            outputSchema: $request->outputSchema,
            userId: $request->userId,
            teamId: $request->teamId,
            experimentId: $request->experimentId,
            experimentStageId: $request->experimentStageId,
            agentId: $request->agentId,
            purpose: $request->purpose,
            idempotencyKey: $request->idempotencyKey,
            temperature: $request->temperature,
            fallbackChain: $request->fallbackChain,
            tools: $request->tools,
            maxSteps: $request->maxSteps,
            toolChoice: $request->toolChoice,
            providerName: $name,
            thinkingBudget: $request->thinkingBudget,
            effort: $request->effort,
            workingDirectory: $request->workingDirectory,
            enablePromptCaching: $request->enablePromptCaching,
            complexity: $request->complexity,
            classifiedComplexity: $request->classifiedComplexity,
            budgetPressureLevel: $request->budgetPressureLevel,
            escalationAttempts: $request->escalationAttempts,
            fastMode: $request->fastMode,
        );
    }

    /**
     * @param  Closure(AiRequestDTO): AiResponseDTO  $handler
     * @return Closure(AiRequestDTO): AiResponseDTO
     */
    private function buildPipeline(Closure $handler): Closure
    {
        return array_reduce(
            array_reverse($this->middleware),
            fn (Closure $next, AiMiddlewareInterface $middleware) => fn (AiRequestDTO $request) => $middleware->handle($request, $next),
            $handler,
        );
    }

    /**
     * Resolve whether the request should run with Anthropic Fast Mode enabled.
     * Requires the global kill-switch to be on; then triggers either on explicit
     * DTO flag or when the request purpose matches a configured auto-enable prefix.
     */
    private function isEffectiveFastMode(AiRequestDTO $request): bool
    {
        if (! (bool) config('ai_routing.fast_mode.enabled', false)) {
            return false;
        }

        if ($request->fastMode) {
            return true;
        }

        $purpose = (string) ($request->purpose ?? '');
        if ($purpose === '') {
            return false;
        }

        foreach ((array) config('ai_routing.fast_mode.auto_enable_purpose_prefixes', []) as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($purpose, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
