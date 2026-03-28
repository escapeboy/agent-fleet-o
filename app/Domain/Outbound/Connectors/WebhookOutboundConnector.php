<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Services\SsrfGuard;
use App\Infrastructure\Security\ShellChainDecomposer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generic HTTP POST webhook connector.
 *
 * Sends outbound data as JSON POST to a configurable URL.
 * Target should contain 'url' and optionally 'headers'.
 */
class WebhookOutboundConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "webhook|{$proposal->id}");

        $existing = OutboundAction::withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $action = OutboundAction::withoutGlobalScopes()->create([
            'team_id' => $proposal->team_id,
            'outbound_proposal_id' => $proposal->id,
            'status' => OutboundActionStatus::Sending,
            'idempotency_key' => $idempotencyKey,
            'retry_count' => 0,
        ]);

        try {
            $target = $proposal->target;
            $content = $proposal->content;

            $url = $target['url'] ?? null;
            if (! $url) {
                // No actionable URL — simulate the send (dry-run)
                Log::info('WebhookOutboundConnector: No URL in target, simulating send', [
                    'proposal_id' => $proposal->id,
                    'target' => app(ShellChainDecomposer::class)->sanitizeForLog((string) json_encode($target)),
                ]);

                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => 'webhook-simulated-'.now()->timestamp,
                    'response' => ['simulated' => true, 'reason' => 'No URL in webhook target'],
                    'sent_at' => now(),
                ]);

                return $action;
            }

            // Reject shell chain operators in URL to prevent SSRF guard bypass.
            if (app(ShellChainDecomposer::class)->containsChain($url)) {
                throw new \InvalidArgumentException('Webhook URL contains shell chain operators and was rejected.');
            }

            // Block SSRF — validate host is a public, routable address.
            app(SsrfGuard::class)->assertPublicUrl($url);

            // Header allowlist — only permit safe custom headers; block Host, Authorization, Cookie etc.
            $allowedHeaderPrefixes = ['x-', 'content-type'];
            $rawHeaders = $target['headers'] ?? [];
            $headers = array_filter(
                $rawHeaders,
                fn ($key) => collect($allowedHeaderPrefixes)->contains(fn ($p) => str_starts_with(strtolower($key), $p)),
                ARRAY_FILTER_USE_KEY,
            );

            $payload = [
                'experiment_id' => $proposal->experiment_id,
                'proposal_id' => $proposal->id,
                'channel' => $proposal->channel->value,
                'content' => $content,
                'target' => array_diff_key($target, array_flip(['url', 'headers'])),
            ];

            // Sign the payload if secret is provided
            $secret = $target['secret'] ?? null;
            if ($secret) {
                $headers['X-Webhook-Signature'] = hash_hmac('sha256', json_encode($payload), $secret);
            }

            $response = Http::timeout(15)
                ->withHeaders($headers)
                ->post($url, $payload);

            if ($response->successful()) {
                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => $response->header('X-Request-Id', 'webhook-'.now()->timestamp),
                    'response' => $response->json() ?? ['body' => substr($response->body(), 0, 500)],
                    'sent_at' => now(),
                ]);
            } else {
                $action->update([
                    'status' => OutboundActionStatus::Failed,
                    'response' => [
                        'status' => $response->status(),
                        'body' => substr($response->body(), 0, 500),
                    ],
                    'retry_count' => $action->retry_count + 1,
                ]);
            }
        } catch (\Throwable $e) {
            // Log full message internally; store sanitised short message in DB to avoid
            // persisting URLs with embedded credentials or sensitive TLS error details.
            Log::error('WebhookOutboundConnector: send failed', [
                'proposal_id' => $proposal->id,
                'error' => $e->getMessage(),
            ]);
            $action->update([
                'status' => OutboundActionStatus::Failed,
                'response' => ['error' => substr(preg_replace('/https?:\/\/\S+/i', '[url]', $e->getMessage()), 0, 200)],
                'retry_count' => $action->retry_count + 1,
            ]);
        }

        return $action;
    }

    public function supports(string $channel): bool
    {
        return $channel === 'webhook';
    }
}
