<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;

/**
 * Supabase Database Webhook signal connector.
 *
 * Receives Change Data Capture (CDC) events pushed from a client's Supabase
 * project via the Supabase Database Webhooks feature (powered by pg_net).
 *
 * Setup: In the Supabase dashboard, go to Database → Webhooks → Create webhook.
 * Point it to POST {your-fleetq-url}/api/signals/webhook
 * Add a custom header: X-Webhook-Secret: <your-secret>
 * Enable REPLICA IDENTITY FULL on the table to get old_record on UPDATE/DELETE.
 *
 * Payload format (standard Supabase Database Webhook):
 * {
 *   "type": "INSERT" | "UPDATE" | "DELETE",
 *   "table": "orders",
 *   "schema": "public",
 *   "record": { ... },      // new row (null on DELETE)
 *   "old_record": { ... }   // old row (only on UPDATE/DELETE with REPLICA IDENTITY FULL)
 * }
 *
 * @see https://supabase.com/docs/guides/database/webhooks
 */
class SupabaseWebhookConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * Process a Supabase Database Webhook push payload.
     *
     * Config expects:
     *   'payload'  => array  (parsed JSON from Supabase)
     *   'team_id'  => string (optional, for multi-tenant setups)
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $payload = $config['payload'] ?? [];
        $teamId = $config['team_id'] ?? null;

        if (empty($payload)) {
            return [];
        }

        $signal = $this->processPayload($payload, $teamId);

        return $signal ? [$signal] : [];
    }

    public function supports(string $driver): bool
    {
        return $driver === 'supabase_webhook';
    }

    /**
     * Validate the X-Webhook-Secret header sent by Supabase.
     *
     * Supabase Database Webhooks send the secret as a plain header value,
     * not as an HMAC. Compare with hash_equals to prevent timing attacks.
     */
    public static function validateSignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        if (empty($signatureHeader)) {
            return false;
        }

        return hash_equals($secret, $signatureHeader);
    }

    /**
     * Process a single Supabase CDC payload and ingest it as a Signal.
     */
    private function processPayload(array $payload, ?string $teamId): ?Signal
    {
        $type = $payload['type'] ?? 'unknown';
        $table = $payload['table'] ?? 'unknown';
        $schema = $payload['schema'] ?? 'public';
        $record = $payload['record'] ?? [];
        $oldRecord = $payload['old_record'] ?? null;

        // Build a stable source identifier: schema.table.pk or timestamp
        $pkValue = $record['id']
            ?? $oldRecord['id']
            ?? uniqid('supa_', true);

        $sourceIdentifier = "{$schema}.{$table}:{$pkValue}";
        $sourceNativeId = "{$schema}.{$table}.".strtolower($type).".{$pkValue}";

        $signalPayload = array_filter([
            'event_type' => $type,
            'schema' => $schema,
            'table' => $table,
            'record' => $record ?: null,
            'old_record' => $oldRecord,
            'source' => 'supabase_cdc',
        ], fn ($v) => $v !== null);

        $tags = array_values(array_filter([
            'supabase',
            'cdc',
            strtolower($type),
            $table,
        ]));

        return $this->ingestAction->execute(
            sourceType: 'supabase_cdc',
            sourceIdentifier: $sourceIdentifier,
            payload: $signalPayload,
            tags: $tags,
            sourceNativeId: $sourceNativeId,
            teamId: $teamId,
        );
    }
}
