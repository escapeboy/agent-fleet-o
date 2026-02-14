<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * Ingest a WhatsApp webhook payload as a signal.
     *
     * Config expects: ['payload' => array, 'experiment_id' => ?string]
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $payload = $config['payload'] ?? [];
        $experimentId = $config['experiment_id'] ?? null;

        $signals = [];
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            foreach ($changes as $change) {
                $value = $change['value'] ?? [];
                $messages = $value['messages'] ?? [];

                foreach ($messages as $message) {
                    $from = $message['from'] ?? 'unknown';
                    $type = $message['type'] ?? 'text';
                    $text = $message['text']['body'] ?? '';

                    if (empty($text) && $type === 'text') {
                        continue;
                    }

                    $signalPayload = [
                        'from' => $from,
                        'type' => $type,
                        'text' => $text,
                        'message_id' => $message['id'] ?? null,
                        'timestamp' => $message['timestamp'] ?? null,
                        'contact_name' => $value['contacts'][0]['profile']['name'] ?? null,
                    ];

                    $signal = $this->ingestAction->execute(
                        sourceType: 'whatsapp',
                        sourceIdentifier: $from,
                        payload: $signalPayload,
                        tags: ['whatsapp', $type],
                        experimentId: $experimentId,
                    );

                    if ($signal) {
                        $signals[] = $signal;
                    }
                }
            }
        }

        if (empty($signals)) {
            Log::debug('WhatsAppWebhookConnector: No messages extracted from payload');
        }

        return $signals;
    }

    public function supports(string $driver): bool
    {
        return $driver === 'whatsapp';
    }

    /**
     * Validate WhatsApp webhook signature (HMAC-SHA256).
     */
    public static function validateSignature(string $payload, string $signature, string $appSecret): bool
    {
        $expected = 'sha256='.hash_hmac('sha256', $payload, $appSecret);

        return hash_equals($expected, $signature);
    }
}
