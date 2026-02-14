<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Log;

class WebhookConnector implements InputConnectorInterface
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
        );

        return $signal ? [$signal] : [];
    }

    public function supports(string $driver): bool
    {
        return $driver === 'webhook';
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
