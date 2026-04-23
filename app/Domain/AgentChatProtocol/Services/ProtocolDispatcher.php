<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\AgentChatProtocol\DTOs\ChatMessageDTO;
use App\Domain\AgentChatProtocol\DTOs\StructuredRequestDTO;
use App\Domain\AgentChatProtocol\Enums\ExternalAgentStatus;
use App\Domain\AgentChatProtocol\Exceptions\RemoteAgentTimeoutException;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Shared\Services\SsrfGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProtocolDispatcher
{
    public function __construct(private readonly SsrfGuard $ssrfGuard) {}

    public function sendChat(ExternalAgent $externalAgent, ChatMessageDTO $message): array
    {
        return $this->dispatch($externalAgent, 'chat', $message->toArray());
    }

    public function sendStructuredRequest(ExternalAgent $externalAgent, StructuredRequestDTO $request): array
    {
        return $this->dispatch($externalAgent, 'structured', $request->toArray());
    }

    public function sendRaw(ExternalAgent $externalAgent, string $path, array $payload): array
    {
        return $this->dispatch($externalAgent, $path, $payload);
    }

    private function dispatch(ExternalAgent $externalAgent, string $subpath, array $payload): array
    {
        if (! $externalAgent->status->isCallable()) {
            throw new \RuntimeException("External agent {$externalAgent->id} is not callable (status: {$externalAgent->status->value})");
        }

        $url = rtrim($externalAgent->endpoint_url, '/').'/'.ltrim($subpath, '/');
        $this->ssrfGuard->assertPublicUrl($url);

        if ($this->isCircuitOpen($externalAgent)) {
            $this->recordFailure($externalAgent, 'circuit breaker open');
            throw new \RuntimeException("Circuit breaker open for external agent {$externalAgent->id}");
        }

        $timeoutSec = min(
            (int) config('agent_chat.outbound.timeout_seconds', 30),
            (int) config('agent_chat.outbound.max_timeout_seconds', 120),
        );
        $retries = (int) config('agent_chat.outbound.retries', 3);
        $baseMs = (int) config('agent_chat.outbound.retry_base_ms', 100);
        $multiplier = (int) config('agent_chat.outbound.retry_multiplier', 4);

        $request = $this->buildRequest($externalAgent, $timeoutSec);

        $lastException = null;
        $start = microtime(true);
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                /** @var Response $response */
                $response = $request->post($url, $payload);

                $status = $response->status();
                if ($status >= 500) {
                    $lastException = new \RuntimeException("Remote returned {$status}");
                    if ($attempt < $retries) {
                        usleep($baseMs * 1000 * ($multiplier ** $attempt));

                        continue;
                    }
                    break;
                }

                if ($status >= 400) {
                    $this->recordFailure($externalAgent, "HTTP {$status}: {$response->body()}");
                    throw new \RuntimeException("Remote returned {$status}: {$response->body()}");
                }

                $this->recordSuccess($externalAgent, (int) ((microtime(true) - $start) * 1000));

                return (array) $response->json();
            } catch (ConnectionException $e) {
                $lastException = new RemoteAgentTimeoutException('Remote agent timeout: '.$e->getMessage(), 0, $e);
                if ($attempt < $retries) {
                    usleep($baseMs * 1000 * ($multiplier ** $attempt));

                    continue;
                }
                break;
            } catch (\Throwable $e) {
                $lastException = $e;
                break;
            }
        }

        $this->recordFailure($externalAgent, $lastException?->getMessage() ?? 'unknown error');
        Log::warning('Agent chat protocol dispatch failed', [
            'external_agent_id' => $externalAgent->id,
            'url' => $url,
            'error' => $lastException?->getMessage(),
        ]);

        throw $lastException ?? new \RuntimeException('Dispatch failed');
    }

    private function buildRequest(ExternalAgent $externalAgent, int $timeoutSec): PendingRequest
    {
        $request = Http::timeout($timeoutSec)
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'FleetQ-AgentChat/1.0',
                'X-FleetQ-Protocol-Version' => (string) config('agent_chat.protocol_version'),
            ]);

        if ($externalAgent->credential_id !== null && $externalAgent->credential !== null) {
            $secret = $externalAgent->credential->secret_data['value'] ?? null;
            if ($secret !== null) {
                $request = $request->withToken((string) $secret);
            }
        }

        return $request;
    }

    private function isCircuitOpen(ExternalAgent $externalAgent): bool
    {
        return (bool) Cache::get($this->breakerKey($externalAgent), false);
    }

    private function breakerKey(ExternalAgent $externalAgent): string
    {
        return 'agent_chat_breaker:'.$externalAgent->id;
    }

    private function failureCountKey(ExternalAgent $externalAgent): string
    {
        return 'agent_chat_breaker_fails:'.$externalAgent->id;
    }

    private function recordSuccess(ExternalAgent $externalAgent, int $latencyMs): void
    {
        $externalAgent->forceFill([
            'last_call_at' => now(),
            'last_success_at' => now(),
            'status' => ExternalAgentStatus::Active,
            'last_error' => null,
        ])->save();

        Cache::forget($this->failureCountKey($externalAgent));
        Cache::forget($this->breakerKey($externalAgent));
    }

    private function recordFailure(ExternalAgent $externalAgent, string $error): void
    {
        $threshold = (int) config('agent_chat.outbound.circuit_breaker_failure_threshold', 5);
        $windowSec = (int) config('agent_chat.outbound.circuit_breaker_window_seconds', 60);
        $openSec = (int) config('agent_chat.outbound.circuit_breaker_open_seconds', 120);

        $count = (int) Cache::get($this->failureCountKey($externalAgent), 0) + 1;
        Cache::put($this->failureCountKey($externalAgent), $count, $windowSec);

        if ($count >= $threshold) {
            Cache::put($this->breakerKey($externalAgent), true, $openSec);
        }

        $externalAgent->forceFill([
            'last_call_at' => now(),
            'last_error' => substr($error, 0, 500),
            'status' => $count >= $threshold ? ExternalAgentStatus::Unreachable : $externalAgent->status,
        ])->save();
    }
}
