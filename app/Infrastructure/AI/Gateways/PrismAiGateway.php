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
        $pipeline = $this->buildPipeline(fn (AiRequestDTO $req) => $this->executeRequest($req));

        return $pipeline($request);
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        // Structured output and tool calling don't support streaming — fall back
        if ($request->isStructured() || $request->hasTools()) {
            return $this->complete($request);
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
        $startTime = hrtime(true);

        $generator = Prism::text()
            ->using($provider, $request->model)
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
        $startTime = hrtime(true);

        if ($request->isStructured()) {
            $response = Prism::structured()
                ->using($provider, $request->model)
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
            ->using($provider, $request->model)
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

        if ($request->hasTools() && $response->steps->isNotEmpty()) {
            $stepsCount = $response->steps->count();
            $toolResultsList = [];

            foreach ($response->steps as $step) {
                foreach ($step->toolCalls as $toolCall) {
                    $toolResultsList[] = [
                        'toolName' => $toolCall->name,
                        'args' => $toolCall->arguments(),
                    ];
                }
            }

            $toolCallsCount = count($toolResultsList);
            $toolResults = $toolCallsCount > 0 ? $toolResultsList : null;
        }

        return new AiResponseDTO(
            content: $response->text,
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
        );
    }

    /**
     * If the request has a teamId, look up team-specific API credentials
     * and inject them into Prism's runtime config. Falls back to platform config.
     */
    private function applyTeamCredentials(AiRequestDTO $request): void
    {
        if (! $request->teamId) {
            return;
        }

        $credential = TeamProviderCredential::where('team_id', $request->teamId)
            ->where('provider', $request->provider)
            ->where('is_active', true)
            ->first();

        if (! $credential) {
            // Check if team's plan allows platform fallback
            $team = Team::find($request->teamId);
            if ($team && ! $team->hasFeature('platform_llm_fallback')) {
                throw new RuntimeException(
                    'Your plan does not include platform AI keys. '
                    .'Please add your own API key in Team Settings, or upgrade your plan.',
                );
            }

            return; // Fall back to platform-level config
        }

        $creds = $credential->credentials;
        $configKey = match ($request->provider) {
            'anthropic' => 'prism.providers.anthropic.api_key',
            'openai' => 'prism.providers.openai.api_key',
            'google' => 'prism.providers.gemini.api_key',
            default => null,
        };

        if ($configKey && isset($creds['api_key'])) {
            config([$configKey => $creds['api_key']]);
        }
    }

    private function resolveProvider(string $provider): Provider
    {
        return match ($provider) {
            'anthropic' => Provider::Anthropic,
            'openai' => Provider::OpenAI,
            'google' => Provider::Gemini,
            default => throw new PrismException("Unsupported provider: {$provider}"),
        };
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
