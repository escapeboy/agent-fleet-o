<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\AgentChatProtocol\DTOs\ChatMessageDTO;
use App\Domain\AgentChatProtocol\DTOs\StructuredRequestDTO;
use App\Domain\AgentChatProtocol\Enums\AdapterKind;
use App\Domain\AgentChatProtocol\Enums\ExternalAgentStatus;
use App\Domain\AgentChatProtocol\Exceptions\A2aDispatchNotSupportedException;
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
    public function __construct(
        private readonly SsrfGuard $ssrfGuard,
        private readonly AgentverseEnvelopeMapper $envelopeMapper,
        private readonly A2aClient $a2aClient,
    ) {}

    public function sendChat(ExternalAgent $externalAgent, ChatMessageDTO $message): array
    {
        $this->assertDispatchable($externalAgent);

        if ($this->adapterKind($externalAgent)->isA2a()) {
            return $this->dispatchA2a($externalAgent, $message->content, $message->msgId);
        }

        if ($this->adapterKind($externalAgent)->isAgentverse()) {
            return $this->dispatchAgentverse($externalAgent, $message);
        }

        return $this->dispatch($externalAgent, 'chat', $message->toArray());
    }

    public function sendStructuredRequest(ExternalAgent $externalAgent, StructuredRequestDTO $request): array
    {
        $this->assertDispatchable($externalAgent);

        if ($this->adapterKind($externalAgent)->isA2a()) {
            // A2A has no native structured/schema channel — send the prompt as a
            // text turn; the schema travels for the peer's benefit but parsing is
            // best-effort on our side.
            return $this->dispatchA2a($externalAgent, $request->prompt, $request->msgId);
        }

        if ($this->adapterKind($externalAgent)->isAgentverse()) {
            return $this->dispatchAgentverse($externalAgent, $request);
        }

        return $this->dispatch($externalAgent, 'structured', $request->toArray());
    }

    /**
     * A2A agents are discoverable always; they are callable only once A2A
     * dispatch is explicitly enabled. With the flag off, fail loudly here so an
     * A2A agent never falls through to the generic HTTP POST path (which is not
     * the A2A JSON-RPC wire protocol).
     */
    private function assertDispatchable(ExternalAgent $externalAgent): void
    {
        if ($this->adapterKind($externalAgent)->isA2a() && ! config('agent_chat.a2a.dispatch_enabled', false)) {
            throw new A2aDispatchNotSupportedException(
                "External agent {$externalAgent->id} uses the A2A adapter; A2A message dispatch is disabled (set A2A_DISPATCH_ENABLED=true to enable).",
            );
        }
    }

    /**
     * Dispatch to an A2A agent over JSON-RPC (message/send), wrapped in the same
     * callable/circuit-breaker guards as the other adapters.
     *
     * @return array<string, mixed>
     */
    private function dispatchA2a(ExternalAgent $externalAgent, string $text, string $messageId): array
    {
        if (! $externalAgent->status->isCallable()) {
            throw new \RuntimeException("External agent {$externalAgent->id} is not callable (status: {$externalAgent->status->value})");
        }

        if ($this->isCircuitOpen($externalAgent)) {
            $this->recordFailure($externalAgent, 'circuit breaker open');
            throw new \RuntimeException("Circuit breaker open for external agent {$externalAgent->id}");
        }

        $start = microtime(true);
        try {
            $result = $this->a2aClient->sendMessage($externalAgent, $text, $messageId);
            $this->recordSuccess($externalAgent, (int) ((microtime(true) - $start) * 1000));

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($externalAgent, $e->getMessage());
            throw $e;
        }
    }

    private function adapterKind(ExternalAgent $externalAgent): AdapterKind
    {
        if ($externalAgent->adapter_kind instanceof AdapterKind) {
            return $externalAgent->adapter_kind;
        }

        return AdapterKind::tryFrom((string) $externalAgent->adapter_kind) ?? AdapterKind::Http;
    }

    /**
     * Dispatch through an Agentverse adapter — wrap the ACP payload in the
     * Agentverse envelope and POST to mailbox/submit (or proxy/submit).
     */
    private function dispatchAgentverse(ExternalAgent $externalAgent, ChatMessageDTO|StructuredRequestDTO $dto): array
    {
        if (! $externalAgent->status->isCallable()) {
            throw new \RuntimeException("External agent {$externalAgent->id} is not callable (status: {$externalAgent->status->value})");
        }

        if ($this->isCircuitOpen($externalAgent)) {
            $this->recordFailure($externalAgent, 'circuit breaker open');
            throw new \RuntimeException("Circuit breaker open for external agent {$externalAgent->id}");
        }

        $targetAddress = (string) ($externalAgent->agent_address ?? '');
        if ($targetAddress === '') {
            throw new \RuntimeException("Agentverse external agent {$externalAgent->id} has no agent_address set");
        }

        // AgentverseClient::forTeam() always returns a client — Agentverse public
        // browse + mailbox submit work without auth; credential is optional.
        $client = AgentverseClient::forTeam((string) $externalAgent->team_id);

        $callerAddress = 'fleetq:team:'.$externalAgent->team_id;
        $envelope = $this->envelopeMapper->wrap($dto, $callerAddress, $targetAddress);

        $start = microtime(true);
        try {
            $response = $this->adapterKind($externalAgent) === AdapterKind::AgentverseProxy
                ? $client->submitProxy($envelope)
                : $client->submitMailbox($envelope);

            $this->recordSuccess($externalAgent, (int) ((microtime(true) - $start) * 1000));

            // Agentverse responses may themselves be envelopes; unwrap and return the payload.
            if (isset($response['payload']) && is_array($response['payload'])) {
                return $response['payload'];
            }

            return $response;
        } catch (\Throwable $e) {
            $this->recordFailure($externalAgent, $e->getMessage());
            throw $e;
        }
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
