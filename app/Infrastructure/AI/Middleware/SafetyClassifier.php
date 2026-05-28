<?php

namespace App\Infrastructure\AI\Middleware;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Events\SafetyViolationDetected;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Gateway-level safety net. Inspects request input and response output
 * against configured rule packs (regex / contains) plus an optional
 * LLM-based classifier. Distinct from Skill-based guardrails (which run
 * inside an agent's tool loop) — this middleware enforces uniformly on
 * every gateway call.
 *
 * Fail-open: any exception inside the classifier is logged but never
 * propagated; safety middleware MUST NOT crash a request.
 */
class SafetyClassifier implements AiMiddlewareInterface
{
    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        if (! $this->isEnabledFor($request)) {
            return $next($request);
        }

        try {
            $inputViolation = $this->scan(
                $request->systemPrompt."\n".$request->userPrompt,
                'input',
            );
        } catch (\Throwable $e) {
            Log::warning('SafetyClassifier: input scan failed', ['error' => $e->getMessage()]);
            $inputViolation = null;
        }

        if ($inputViolation !== null) {
            return $this->handleViolation($request, $inputViolation, fn () => $next($request));
        }

        $response = $next($request);

        try {
            $outputViolation = $this->scan($response->content, 'output');
        } catch (\Throwable $e) {
            Log::warning('SafetyClassifier: output scan failed', ['error' => $e->getMessage()]);
            $outputViolation = null;
        }

        if ($outputViolation !== null) {
            return $this->handleViolation($request, $outputViolation, fn () => $response, $response);
        }

        return $response;
    }

    private function isEnabledFor(AiRequestDTO $request): bool
    {
        if (! (bool) config('ai_safety.enabled', false)) {
            return false;
        }

        if ($request->teamId === null) {
            return false;
        }

        return Cache::remember(
            'ai_safety:enabled:'.$request->teamId,
            60,
            function () use ($request) {
                $team = Team::withoutGlobalScopes()->find($request->teamId);

                return (bool) ($team?->settings['safety_classifier_enabled'] ?? false);
            },
        );
    }

    /**
     * @return array{rule_id: string, severity: string, target: string, snippet: string}|null
     */
    private function scan(string $content, string $direction): ?array
    {
        if ($content === '') {
            return null;
        }

        $rules = config('ai_safety.rules', []);

        foreach ($rules as $rule) {
            $target = $rule['target'] ?? 'both';

            if ($target !== 'both' && $target !== $direction) {
                continue;
            }

            if ($this->matches($rule, $content)) {
                return [
                    'rule_id' => $rule['id'],
                    'severity' => $rule['severity'] ?? 'low',
                    'target' => $direction,
                    'snippet' => $this->extractSnippet($content, $rule),
                ];
            }
        }

        return null;
    }

    private function matches(array $rule, string $content): bool
    {
        $kind = $rule['kind'] ?? 'contains';
        $pattern = $rule['pattern'] ?? '';

        if ($pattern === '') {
            return false;
        }

        if ($kind === 'regex') {
            $result = @preg_match($pattern, $content);

            return $result === 1;
        }

        return stripos($content, $pattern) !== false;
    }

    private function extractSnippet(string $content, array $rule): string
    {
        $maxLen = 120;

        if ($rule['kind'] === 'contains') {
            $pos = stripos($content, $rule['pattern']);

            if ($pos === false) {
                return mb_substr($content, 0, $maxLen);
            }

            $start = max(0, $pos - 20);

            return mb_substr($content, $start, $maxLen);
        }

        if (@preg_match($rule['pattern'], $content, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $offset = max(0, $matches[0][1] - 20);

            return mb_substr($content, $offset, $maxLen);
        }

        return mb_substr($content, 0, $maxLen);
    }

    /**
     * @param  array{rule_id: string, severity: string, target: string, snippet: string}  $violation
     * @param  Closure(): AiResponseDTO  $passThrough
     */
    private function handleViolation(
        AiRequestDTO $request,
        array $violation,
        Closure $passThrough,
        ?AiResponseDTO $existingResponse = null,
    ): AiResponseDTO {
        $mode = (string) config('ai_safety.mode', 'advisory');
        $strikeCount = $this->recordStrike($request);

        Log::warning('AI safety violation detected', [
            'rule_id' => $violation['rule_id'],
            'severity' => $violation['severity'],
            'target' => $violation['target'],
            'team_id' => $request->teamId,
            'mode' => $mode,
            'strike_count' => $strikeCount,
        ]);

        event(new SafetyViolationDetected($request, $violation, $mode, $strikeCount));

        if ($mode === 'block') {
            return $this->refusalResponse($request);
        }

        // advisory: pass through unmodified
        return $existingResponse ?? $passThrough();
    }

    private function refusalResponse(AiRequestDTO $request): AiResponseDTO
    {
        $refusal = (string) config(
            'ai_safety.refusal_message',
            'This response was blocked by your team\'s AI safety policy.',
        );

        return new AiResponseDTO(
            content: $refusal,
            parsedOutput: null,
            usage: new \App\Infrastructure\AI\DTOs\AiUsageDTO(
                promptTokens: 0,
                completionTokens: 0,
                costCredits: 0,
            ),
            provider: $request->provider,
            model: $request->model,
            latencyMs: 0,
            schemaValid: false,
        );
    }

    private function recordStrike(AiRequestDTO $request): int
    {
        $window = (int) config('ai_safety.strike_window_seconds', 3600);

        if ($window <= 0 || $request->teamId === null) {
            return 0;
        }

        $key = 'ai_safety:strikes:'.$request->teamId;
        $count = Cache::increment($key);

        if ($count === 1) {
            // First strike — set TTL via re-put. predis store doesn't support EX on incr.
            Cache::put($key, 1, $window);
        }

        return (int) $count;
    }
}
