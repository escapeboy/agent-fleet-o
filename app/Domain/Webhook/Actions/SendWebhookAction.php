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

            $call = WebhookCall::create()
                ->url($endpoint->url)
                ->payload($payload)
                ->useSecret($endpoint->secret ?? '')
                ->maximumTries($retryConfig['max_retries'] ?? 3);

            // Add custom headers if configured
            /** @var array<string, string> $headers */
            $headers = $endpoint->headers ?? [];
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
}
