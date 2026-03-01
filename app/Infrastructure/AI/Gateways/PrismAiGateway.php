<?php

namespace App\Infrastructure\AI\Gateways;

use App\Domain\Budget\Services\CostCalculator;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Closure;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
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
        if ($request->teamId) {
            app()->instance('ai.current_team_id', $request->teamId);
        }

        $pipeline = $this->buildPipeline(fn (AiRequestDTO $req) => $this->executeRequest($req));

        return $pipeline($request);
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        // Structured output and tool calling don't support streaming — fall back
        if ($request->isStructured() || $request->hasTools()) {
            return $this->complete($request);
        }

        if ($request->teamId) {
            app()->instance('ai.current_team_id', $request->teamId);
        }

        $pipeline = $this->buildPipeline(fn (AiRequestDTO $req) => $this->executeStreamRequest($req, $onChunk));

        return $pipeline($request);
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
        $localConfig = $this->getLocalProviderConfig($request);
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
                ),
            ),
            provider: $request->provider,
            model: $request->model,
            latencyMs: $latencyMs,
        );
    }

    private function executeRequest(AiRequestDTO $request): AiResponseDTO
    {
        $provider = $this->resolveProvider($request->provider);
        $this->applyTeamCredentials($request);
        $localConfig = $this->getLocalProviderConfig($request);
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
                usage: new AiUsageDTO(
                    promptTokens: $response->usage->promptTokens,
                    completionTokens: $response->usage->completionTokens,
                    costCredits: $this->costCalculator->calculateCost(
                        provider: $request->provider,
                        model: $request->model,
                        inputTokens: $response->usage->promptTokens,
                        outputTokens: $response->usage->completionTokens,
                    ),
                ),
                provider: $request->provider,
                model: $request->model,
                latencyMs: $latencyMs,
                schemaValid: true,
            );
        }

        $builder = Prism::text()
            ->using($provider, $request->model, $localConfig)
            ->withSystemPrompt($request->systemPrompt)
            ->withPrompt($request->userPrompt)
            ->withMaxTokens($request->maxTokens)
            ->usingTemperature($request->temperature)
            ->withClientOptions(['timeout' => 120])
            ->withClientRetry(2, 500);

        // Add tool support when tools are provided
        if ($request->hasTools()) {
            $builder->withTools($request->tools)
                ->withMaxSteps($request->maxSteps);

            if ($request->toolChoice !== null) {
                $builder->withToolChoice($request->toolChoice);
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

        // Extract <thinking> blocks from Anthropic extended thinking responses
        $text = $response->text;
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
            usage: new AiUsageDTO(
                promptTokens: $response->usage->promptTokens,
                completionTokens: $response->usage->completionTokens,
                costCredits: $this->costCalculator->calculateCost(
                    provider: $request->provider,
                    model: $request->model,
                    inputTokens: $response->usage->promptTokens,
                    outputTokens: $response->usage->completionTokens,
                ),
            ),
            provider: $request->provider,
            model: $request->model,
            latencyMs: $latencyMs,
            toolResults: $toolResults,
            toolCallsCount: $toolCallsCount,
            stepsCount: $stepsCount,
            reasoningChain: $reasoningChain,
        );
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
        if (in_array($request->provider, ['ollama', 'openai_compatible'], true)) {
            return; // Handled via getLocalProviderConfig()
        }

        if (! $request->teamId) {
            return;
        }

        $configKey = match ($request->provider) {
            'anthropic' => 'prism.providers.anthropic.api_key',
            'openai' => 'prism.providers.openai.api_key',
            'google' => 'prism.providers.gemini.api_key',
            default => null,
        };

        $credential = TeamProviderCredential::where('team_id', $request->teamId)
            ->where('provider', $request->provider)
            ->where('is_active', true)
            ->first();

        if ($credential && $configKey && isset($credential->credentials['api_key'])) {
            config([$configKey => $credential->credentials['api_key']]);

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

        // Explicitly restore the original platform API key (read from the immutable
        // process environment) to clear any BYOK key set by a prior Horizon job.
        if ($configKey) {
            $platformKey = match ($request->provider) {
                'anthropic' => env('ANTHROPIC_API_KEY'),
                'openai' => env('OPENAI_API_KEY'),
                'google' => env('GOOGLE_AI_API_KEY'),
                default => null,
            };
            config([$configKey => $platformKey]);
        }
    }

    private function resolveProvider(string $provider): Provider|string
    {
        return match ($provider) {
            'anthropic' => Provider::Anthropic,
            'openai' => Provider::OpenAI,
            'google' => Provider::Gemini,
            'ollama' => Provider::Ollama,
            'openai_compatible' => 'openai_compatible', // Custom PrismManager extension
            default => throw new PrismException("Unsupported provider: {$provider}"),
        };
    }

    /**
     * For local HTTP providers (ollama, openai_compatible), resolve the base URL
     * and optional API key from team credentials and return as per-request config.
     *
     * @return array<string, string>
     */
    private function getLocalProviderConfig(AiRequestDTO $request): array
    {
        if (! in_array($request->provider, ['ollama', 'openai_compatible'], true)) {
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
}
