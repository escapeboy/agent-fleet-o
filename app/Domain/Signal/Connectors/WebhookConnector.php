<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use App\Mcp\Contracts\AutoRegistersAsMcpTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;

class WebhookConnector implements AutoRegistersAsMcpTool, InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * Ingest a webhook payload as a signal.
     *
     * Config expects: ['payload' => array, 'source' => string, 'experiment_id' => ?string, 'tags' => ?array]
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $payload = $config['payload'] ?? [];
        $source = $config['source'] ?? 'webhook';
        $experimentId = $config['experiment_id'] ?? null;
        $tags = $config['tags'] ?? ['webhook'];
        $files = $config['files'] ?? [];

        if (empty($payload)) {
            Log::warning('WebhookConnector: Empty payload');

            return [];
        }

        // Flatten nested file arrays from multipart uploads
        $flatFiles = [];
        array_walk_recursive($files, function ($file) use (&$flatFiles) {
            $flatFiles[] = $file;
        });

        $signal = $this->ingestAction->execute(
            sourceType: 'webhook',
            sourceIdentifier: $source,
            payload: $payload,
            tags: $tags,
            experimentId: $experimentId,
            files: $flatFiles,
            teamId: $config['team_id'] ?? null,
        );

        return $signal ? [$signal] : [];
    }

    public function supports(string $driver): bool
    {
        return $driver === 'webhook';
    }

    // -------------------------------------------------------------------------
    // AutoRegistersAsMcpTool — exposes this connector as MCP tool "signal.webhook.ingest"
    // -------------------------------------------------------------------------

    public function mcpName(): string
    {
        return 'signal.webhook.ingest';
    }

    public function mcpDescription(): string
    {
        return 'Ingest an arbitrary webhook payload as a Signal in the current team — useful for forwarding payloads from sources without a dedicated connector.';
    }

    public function mcpInputSchema(JsonSchema $schema): array
    {
        return [
            'payload' => $schema->object()->required()
                ->description('Arbitrary JSON payload to record on the signal.'),
            'source' => $schema->string()
                ->description('Free-text source label (e.g. "stripe", "internal-cron"). Defaults to "webhook".'),
            'experiment_id' => $schema->string()
                ->description('Optional experiment UUID to associate the signal with.'),
            'tags' => $schema->array()
                ->description('Optional tags applied to the signal. Defaults to ["webhook"].'),
        ];
    }

    public function mcpInvoke(array $params, string $teamId): array
    {
        $params['team_id'] = $teamId;
        $signals = $this->poll($params);

        return [
            'count' => count($signals),
            'signal_ids' => array_map(fn (Signal $s) => $s->id, $signals),
        ];
    }

    public function mcpAnnotations(): array
    {
        return ['read_only' => false, 'idempotent' => false, 'assistant_tool' => 'write'];
    }

    /**
     * Validate a webhook signature (HMAC-SHA256).
     */
    public static function validateSignature(string $payload, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }
}
