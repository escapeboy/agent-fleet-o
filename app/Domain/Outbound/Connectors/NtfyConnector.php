<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Outbound\Services\OutboundCredentialResolver;
use App\Domain\Shared\Services\SsrfGuard;
use Illuminate\Support\Facades\Http;

/**
 * Ntfy push notification connector.
 *
 * Sends messages via ntfy HTTP publish API (https://docs.ntfy.sh/publish/).
 * Credentials shape (OutboundConnectorConfig.credentials):
 *   base_url       — ntfy server base URL (default: https://ntfy.sh)
 *   topic          — topic name (required)
 *   default_priority — min|low|default|high|max (default: default)
 *   default_tags   — comma-separated emoji shortcode tags (default: '')
 *   token          — optional bearer token for private topics
 */
class NtfyConnector implements OutboundConnectorInterface
{
    /**
     * Priority level mapping from semantic names to ntfy values.
     */
    private const PRIORITY_MAP = [
        'critical' => 'max',
        'high' => 'high',
        'normal' => 'default',
        'low' => 'low',
    ];

    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "ntfy|{$proposal->id}");

        $existing = OutboundAction::withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $action = OutboundAction::withoutGlobalScopes()->create([
            'team_id' => $proposal->team_id, // @phpstan-ignore property.notFound
            'outbound_proposal_id' => $proposal->id,
            'status' => OutboundActionStatus::Sending,
            'idempotency_key' => $idempotencyKey,
            'retry_count' => 0,
        ]);

        try {
            $resolver = app(OutboundCredentialResolver::class);
            /** @var array<string, mixed> $proposalTarget */
            $proposalTarget = $proposal->target; // @phpstan-ignore property.notFound
            $creds = $resolver->resolve('ntfy', $proposalTarget, $proposal->team_id); // @phpstan-ignore property.notFound

            $baseUrl = rtrim($creds['base_url'] ?? 'https://ntfy.sh', '/');
            $topic = $creds['topic'] ?? ($proposalTarget['topic'] ?? null);

            if (! $topic) {
                throw new \RuntimeException('Ntfy topic not configured');
            }

            $url = $baseUrl.'/'.$topic;

            // Block SSRF — validate the assembled URL is a public, routable address.
            app(SsrfGuard::class)->assertPublicUrl($url);

            /** @var array<string, mixed> $content */
            $content = $proposal->content; // @phpstan-ignore property.notFound
            $body = $content['body'] ?? $content['text'] ?? 'No content generated.';
            $title = $content['title'] ?? ($proposalTarget['title'] ?? null);

            // Resolve priority: proposal content > proposal target > connector default > 'default'.
            $rawPriority = $content['priority'] ?? $proposalTarget['priority'] ?? $creds['default_priority'] ?? 'default';
            $priority = self::PRIORITY_MAP[$rawPriority] ?? $rawPriority;

            // Tags: proposal overrides connector default.
            $tags = $content['tags'] ?? $proposalTarget['tags'] ?? $creds['default_tags'] ?? '';

            // Build request headers.
            $headers = [
                'Priority' => $priority,
            ];

            if ($title) {
                $headers['Title'] = $title;
            }

            if ($tags) {
                $headers['Tags'] = $tags;
            }

            // Bearer token for private topics.
            $token = $creds['token'] ?? null;
            if ($token) {
                $headers['Authorization'] = 'Bearer '.$token;
            }

            $response = Http::timeout(15)
                ->withHeaders($headers)
                ->withBody($body, 'text/plain')
                ->post($url);

            if ($response->successful()) {
                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => $response->json('id') ?? 'ntfy-'.now()->timestamp,
                    'response' => ['id' => $response->json('id'), 'topic' => $response->json('topic')],
                    'sent_at' => now(),
                ]);
            } else {
                $action->update([
                    'status' => OutboundActionStatus::Failed,
                    'response' => ['error' => $response->body(), 'status' => $response->status()],
                    'retry_count' => $action->retry_count + 1,
                ]);
            }
        } catch (\Throwable $e) {
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
        return $channel === 'ntfy';
    }
}
