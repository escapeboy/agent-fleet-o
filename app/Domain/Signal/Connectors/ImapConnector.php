<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;

class ImapConnector implements InputConnectorInterface
{
    private const MAX_ATTACHMENT_SIZE = 10 * 1024 * 1024; // 10 MB

    private const MAX_ATTACHMENTS_PER_EMAIL = 5;

    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? 993;
        $encryption = $config['encryption'] ?? 'ssl';
        $folder = $config['folder'] ?? 'INBOX';
        $maxPerPoll = min($config['max_per_poll'] ?? 50, 100);
        $lastUid = $config['last_uid'] ?? 0;
        $credentialId = $config['credential_id'] ?? null;
        $experimentId = $config['experiment_id'] ?? null;
        $tags = $config['tags'] ?? ['email'];

        if (! $host) {
            Log::warning('ImapConnector: No host provided');

            return [];
        }

        $credentials = $this->resolveCredentials($credentialId);
        if (! $credentials) {
            Log::warning('ImapConnector: No credentials found', ['credential_id' => $credentialId]);

            return [];
        }

        try {
            $cm = new ClientManager;
            $client = $cm->make([
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'validate_cert' => true,
                'username' => $credentials['username'],
                'password' => $credentials['password'],
                'protocol' => 'imap',
            ]);

            $client->connect();
            $mailFolder = $client->getFolder($folder);

            if (! $mailFolder) {
                Log::warning('ImapConnector: Folder not found', ['folder' => $folder]);

                return [];
            }

            // Fetch messages with UID greater than last processed
            $query = $mailFolder->messages();
            if ($lastUid > 0) {
                $query->setFetchFlags(false);
                $query->whereUid('>', $lastUid);
            }
            $messages = $query->limit($maxPerPoll)->get();

            $signals = [];
            $highestUid = $lastUid;

            foreach ($messages as $message) {
                $uid = $message->getUid();
                if ($uid <= $lastUid) {
                    continue;
                }

                $payload = [
                    'subject' => (string) $message->getSubject(),
                    'from' => $this->formatAddress($message->getFrom()),
                    'to' => $this->formatAddresses($message->getTo()),
                    'cc' => $this->formatAddresses($message->getCc()),
                    'date' => $message->getDate()?->format('c'),
                    'body' => $this->extractBody($message),
                    'message_id' => (string) $message->getMessageId(),
                    'uid' => $uid,
                ];

                $signal = $this->ingestAction->execute(
                    sourceType: 'email',
                    sourceIdentifier: $payload['from'] ?: "imap:{$host}",
                    payload: $payload,
                    tags: $tags,
                    experimentId: $experimentId,
                );

                if ($signal) {
                    $this->processAttachments($message, $signal);
                    $signals[] = $signal;
                }

                $highestUid = max($highestUid, $uid);
            }

            $client->disconnect();

            // Store highest UID for next poll (caller updates connector config)
            if ($highestUid > $lastUid) {
                $config['last_uid'] = $highestUid;
            }

            return $signals;
        } catch (\Throwable $e) {
            Log::error('ImapConnector: Error polling mailbox', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'imap';
    }

    /**
     * Return the updated config with new last_uid watermark.
     */
    public function getUpdatedConfig(array $config, array $signals): array
    {
        if (empty($signals)) {
            return $config;
        }

        $maxUid = 0;
        foreach ($signals as $signal) {
            $uid = $signal->payload['uid'] ?? 0;
            $maxUid = max($maxUid, $uid);
        }

        if ($maxUid > ($config['last_uid'] ?? 0)) {
            $config['last_uid'] = $maxUid;
        }

        return $config;
    }

    private function resolveCredentials(?string $credentialId): ?array
    {
        if (! $credentialId) {
            return null;
        }

        $credential = TeamProviderCredential::find($credentialId);
        if (! $credential || ! $credential->is_active) {
            return null;
        }

        return $credential->credentials;
    }

    private function formatAddress($address): string
    {
        if (! $address || $address->count() === 0) {
            return '';
        }

        $first = $address->first();

        return $first->mail ?? (string) $first;
    }

    private function formatAddresses($addresses): array
    {
        if (! $addresses) {
            return [];
        }

        $result = [];
        foreach ($addresses as $address) {
            $result[] = $address->mail ?? (string) $address;
        }

        return $result;
    }

    private function extractBody($message): string
    {
        $body = $message->getTextBody();
        if (empty($body)) {
            $html = $message->getHTMLBody();
            $body = strip_tags($html);
        }

        // Limit body size to prevent excessively large signals
        return mb_substr(trim($body), 0, 50000);
    }

    private function processAttachments($message, Signal $signal): void
    {
        $attachments = $message->getAttachments();
        $count = 0;

        foreach ($attachments as $attachment) {
            if ($count >= self::MAX_ATTACHMENTS_PER_EMAIL) {
                break;
            }

            $size = $attachment->getSize();
            if ($size > self::MAX_ATTACHMENT_SIZE) {
                Log::debug('ImapConnector: Skipping oversized attachment', [
                    'name' => $attachment->getName(),
                    'size' => $size,
                ]);

                continue;
            }

            try {
                $tempPath = tempnam(sys_get_temp_dir(), 'imap_');
                file_put_contents($tempPath, $attachment->getContent());

                $signal->addMedia($tempPath)
                    ->usingFileName($attachment->getName() ?: 'attachment_'.$count)
                    ->toMediaCollection('attachments');

                $count++;
            } catch (\Throwable $e) {
                Log::warning('ImapConnector: Failed to process attachment', [
                    'name' => $attachment->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
