<?php

namespace App\Domain\Webhook\Actions;

use App\Domain\Webhook\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookServer\WebhookCall;

class SendWebhookAction
{
    public function execute(string $event, array $data, ?string $teamId = null): int
    {
        $query = WebhookEndpoint::active();

        if ($teamId) {
            $query->withoutGlobalScopes()->where('team_id', $teamId);
        }

        $endpoints = $query->get();
        $dispatched = 0;

        foreach ($endpoints as $endpoint) {
            if (! $endpoint->subscribesTo($event)) {
                continue;
            }

            $this->dispatch($endpoint, $event, $data);
            $dispatched++;
        }

        return $dispatched;
    }

    private function dispatch(WebhookEndpoint $endpoint, string $event, array $data): void
    {
        $payload = [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ];

        try {
            /** @var array $retryConfig */
            $retryConfig = $endpoint->retry_config;
            $secret = $endpoint->secret ?? '';

            $call = WebhookCall::create()
                ->url($endpoint->url)
                ->payload($payload)
                ->maximumTries($retryConfig['max_retries'] ?? 3);

            // Spatie's useSecret() throws if the secret is empty; only attach
            // its built-in `Signature` header when we actually have a secret.
            // Without a secret we must opt out of signing entirely.
            if ($secret !== '') {
                $call->useSecret($secret);
            } else {
                $call->doNotSign();
            }

            // Merge static headers + FleetQ-format signature header.
            // Spatie's default `Signature` header is still added by useSecret()
            // above for backward compat; the FleetQ-format header below is
            // what partner verifiers (e.g. fleetq-finance) expect.
            /** @var array<string, string> $headers */
            $headers = $endpoint->headers ?? [];

            $signatureHeader = $this->buildSignatureHeader($endpoint, $payload, $secret);
            if ($signatureHeader !== null) {
                [$name, $value] = $signatureHeader;
                $headers[$name] = $value;
            }

            if (! empty($headers)) {
                $call->withHeaders($headers);
            }

            $call->dispatch();

            $endpoint->recordSuccess();

            Log::info('Webhook dispatched', [
                'endpoint_id' => $endpoint->id,
                'event' => $event,
                'url' => $endpoint->url,
            ]);
        } catch (\Throwable $e) {
            $endpoint->recordFailure();

            Log::error('Webhook dispatch failed', [
                'endpoint_id' => $endpoint->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the FleetQ-format signature header for the outbound webhook.
     *
     * Default: `X-Fleetq-Signature: sha256=<hex>` over `json_encode($payload)`,
     * matching the wire body that Spatie sends. Configurable per-endpoint via
     * signature_header / signature_format / signature_algo.
     *
     * @return array{0: string, 1: string}|null
     */
    private function buildSignatureHeader(WebhookEndpoint $endpoint, array $payload, string $secret): ?array
    {
        if ($secret === '') {
            return null;
        }

        $headerName = $endpoint->signature_header ?: 'X-Fleetq-Signature';
        $format = $endpoint->signature_format ?: 'sha256={hex}';
        $algo = $endpoint->signature_algo ?: 'sha256';

        if (! in_array($algo, hash_hmac_algos(), true)) {
            return null;
        }

        $body = json_encode($payload);
        if ($body === false) {
            return null;
        }

        $hex = hash_hmac($algo, $body, $secret);
        $value = str_replace('{hex}', $hex, $format);

        return [$headerName, $value];
    }
}
