<?php

namespace App\Domain\Decision\Drivers;

use App\Domain\Decision\Contracts\BatchDecisionModel;
use App\Domain\Decision\Contracts\DecisionModel;
use App\Domain\Decision\DTOs\Answer;
use App\Domain\Decision\DTOs\ChoiceAnswer;
use App\Domain\Decision\DTOs\DecisionResult;
use App\Domain\Decision\DTOs\NoulAnswer;
use App\Domain\Decision\DTOs\ScoreAnswer;
use App\Domain\Decision\Exceptions\DecisionRequestException;
use App\Domain\Decision\Services\CredentialRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * HTTP driver for a System One endpoint (TypeSafe Jev, or a compatible host
 * such as Jeff). One POST carries the state and every question; the response
 * comes back as one typed answer per question id.
 *
 * Wire format: https://docs.typesafe.ai/api
 */
class SystemOneDriver implements BatchDecisionModel, DecisionModel
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly float $timeout = 5.0,
        private readonly int $retries = 3,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function decide(array|string $state, array $questions): DecisionResult
    {
        $startedAt = hrtime(true);

        $response = $this->send([
            'model' => $this->model,
            'state' => $state,
            'questions' => $questions,
        ]);

        return $this->toResult($response, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    private function toResult(Response $response, int $latencyMs): DecisionResult
    {
        $body = $response->json();

        if (! is_array($body) || ! isset($body['answers']) || ! is_array($body['answers'])) {
            throw new DecisionRequestException('System One response had no answers map.', $response->status());
        }

        $answers = [];

        foreach ($body['answers'] as $questionId => $answer) {
            $answers[(string) $questionId] = $this->mapAnswer((array) $answer);
        }

        return new DecisionResult(
            answers: $answers,
            model: is_string($body['model'] ?? null) ? $body['model'] : $this->model,
            inputTokens: (int) ($body['usage']['input_tokens'] ?? 0),
            latencyMs: $latencyMs,
        );
    }

    /**
     * Sends every request in one parallel pool, then re-pools whatever came back
     * 429 — up to $retries extra rounds, waiting for the longest Retry-After the
     * batch reported. Retrying per-request inside a pool would defeat the pool;
     * retrying the whole round keeps the rate limiter's accounting honest.
     *
     * A case that still fails is returned as its Throwable rather than aborting
     * the pool, so one bad case cannot lose the other seven results in flight.
     */
    public function decideBatch(array $requests): array
    {
        $results = [];
        $pending = $requests;

        for ($round = 0; $round <= $this->retries; $round++) {
            if ($pending === []) {
                break;
            }

            $startedAt = hrtime(true);

            $responses = $this->http->pool(function ($pool) use ($pending): array {
                $calls = [];

                foreach ($pending as $key => $request) {
                    $calls[] = $pool->as((string) $key)
                        ->timeout($this->timeout)
                        ->withHeaders([
                            'Authorization' => 'Bearer '.$this->apiKey,
                            'Content-Type' => 'application/json',
                        ])
                        ->post($this->baseUrl.'/v1/systemone', [
                            'model' => $this->model,
                            'state' => $request['state'],
                            'questions' => $request['questions'],
                        ]);
                }

                return $calls;
            });

            $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $retryAfterMs = 0;
            $retryable = [];

            foreach ($pending as $key => $request) {
                $response = $responses[(string) $key] ?? null;

                if ($response instanceof Throwable) {
                    $results[$key] = new DecisionRequestException(
                        CredentialRedactor::scrub('System One request failed: '.$response->getMessage(), [$this->apiKey]),
                    );

                    continue;
                }

                if (! $response instanceof Response) {
                    $results[$key] = new DecisionRequestException('System One returned no response for case '.$key.'.');

                    continue;
                }

                if ($response->status() === 429 && $round < $this->retries) {
                    $retryable[$key] = $request;
                    $retryAfterMs = max($retryAfterMs, $this->retryAfterMs($response));

                    continue;
                }

                $results[$key] = $this->interpret($response, $latencyMs);
            }

            $pending = $retryable;

            if ($pending !== []) {
                Sleep::usleep(max($retryAfterMs, 200 * 2 ** $round) * 1000);
            }
        }

        return $results;
    }

    private function interpret(Response $response, int $latencyMs): DecisionResult|Throwable
    {
        if (! $response->successful()) {
            return new DecisionRequestException(
                CredentialRedactor::scrub(
                    'System One request failed with status '.$response->status().': '.mb_substr($response->body(), 0, 1000),
                    [$this->apiKey],
                ),
                $response->status(),
            );
        }

        try {
            return $this->toResult($response, $latencyMs);
        } catch (Throwable $e) {
            return $e;
        }
    }

    private function retryAfterMs(Response $response): int
    {
        $retryAfter = $response->header('Retry-After');

        if ($retryAfter === '') {
            return 0;
        }

        if (is_numeric($retryAfter)) {
            return (int) max(0, (float) $retryAfter * 1000);
        }

        $timestamp = strtotime($retryAfter);

        return $timestamp === false ? 0 : (int) max(0, ($timestamp - time()) * 1000);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload): Response
    {
        try {
            return $this->http
                ->baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                // $retries is the number of RETRIES, so total attempts is one more.
                // Only 429 is retried: a 422 is a bad request that will never pass,
                // and a 5xx here is left to the caller so a broken eval run fails fast.
                ->retry(
                    $this->retries + 1,
                    fn (int $attempt, $exception): int => $this->backoffMs($attempt, $exception),
                    fn ($exception): bool => $this->isRateLimited($exception),
                    throw: false,
                )
                ->post('/v1/systemone', $payload)
                ->throw();
        } catch (RequestException $e) {
            // Rethrown as our own type so the original — whose trace can hold the
            // header array that carries the bearer token — never escapes this method.
            throw new DecisionRequestException(
                CredentialRedactor::scrub(
                    'System One request failed with status '.$e->response->status().': '.mb_substr($e->response->body(), 0, 1000),
                    [$this->apiKey],
                ),
                $e->response->status(),
            );
        } catch (ConnectionException $e) {
            throw new DecisionRequestException(
                CredentialRedactor::scrub('System One request could not connect: '.$e->getMessage(), [$this->apiKey]),
            );
        }
    }

    private function isRateLimited(mixed $exception): bool
    {
        return $exception instanceof RequestException && $exception->response->status() === 429;
    }

    /**
     * Honours Retry-After (delta-seconds or HTTP-date) when the response carries
     * one, and falls back to exponential backoff when it does not.
     */
    private function backoffMs(int $attempt, mixed $exception): int
    {
        if ($exception instanceof RequestException) {
            $retryAfter = $exception->response->header('Retry-After');

            if ($retryAfter !== '') {
                if (is_numeric($retryAfter)) {
                    return (int) max(0, (float) $retryAfter * 1000);
                }

                $timestamp = strtotime($retryAfter);

                if ($timestamp !== false) {
                    return (int) max(0, ($timestamp - time()) * 1000);
                }
            }
        }

        return (int) (200 * 2 ** ($attempt - 1));
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function mapAnswer(array $answer): Answer
    {
        $probabilities = isset($answer['probabilities']) && is_array($answer['probabilities'])
            ? array_map(static fn ($p): float => (float) $p, $answer['probabilities'])
            : null;

        $confidence = isset($answer['confidence']) ? (float) $answer['confidence'] : null;

        return match ($answer['type'] ?? null) {
            'choice' => new ChoiceAnswer(
                choice: (string) ($answer['choice'] ?? ''),
                probabilities: $probabilities,
                confidence: $confidence,
            ),
            'score' => new ScoreAnswer(
                score: (float) ($answer['score'] ?? 0),
                probabilities: $probabilities,
                confidence: $confidence,
                legend: isset($answer['legend']) && is_array($answer['legend'])
                    ? array_map(static fn ($l): string => (string) $l, $answer['legend'])
                    : null,
            ),
            'noul' => new NoulAnswer(noul: (float) ($answer['noul'] ?? 0)),
            default => throw new DecisionRequestException('Unknown answer type: '.json_encode($answer['type'] ?? null)),
        };
    }
}
