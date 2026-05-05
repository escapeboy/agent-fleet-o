<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Knowledge\Actions\IngestKnowledgeDocumentAction;
use App\Domain\Signal\Contracts\KnowledgeConnectorInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SlackChannelKnowledgeConnector implements KnowledgeConnectorInterface
{
    private const REDIS_KEY_PREFIX = 'knowledge_sync:';

    private const SLACK_HISTORY_ENDPOINT = 'https://slack.com/api/conversations.history';

    private const SLACK_REPLIES_ENDPOINT = 'https://slack.com/api/conversations.replies';

    private const SLACK_INFO_ENDPOINT = 'https://slack.com/api/conversations.info';

    public function __construct(
        private readonly IngestKnowledgeDocumentAction $ingestAction,
    ) {}

    public function getDriverName(): string
    {
        return 'slack_knowledge';
    }

    public function supports(string $driver): bool
    {
        return $driver === 'slack_knowledge';
    }

    public function isKnowledgeConnector(): bool
    {
        return true;
    }

    public function getLastSyncAt(string $bindingId): ?Carbon
    {
        $value = Redis::get(self::REDIS_KEY_PREFIX.$bindingId);

        return $value ? Carbon::parse($value) : null;
    }

    public function setLastSyncAt(string $bindingId, Carbon $at): void
    {
        Redis::setex(self::REDIS_KEY_PREFIX.$bindingId, 60 * 60 * 24 * 90, $at->toIso8601String());
    }

    /**
     * Poll Slack channels and ingest messages + threads as Memory entries.
     *
     * Config keys:
     *   - bot_token: Slack bot OAuth token (xoxb-...)
     *   - channel_ids: comma-separated Slack channel IDs (C...) to poll
     *   - team_id: owning team
     *   - binding_id: connector binding UUID (for lastSyncAt tracking)
     *   - min_length: minimum message character count to ingest (default: 50)
     *
     * @return array Always empty — knowledge connectors write to Memory directly.
     */
    public function poll(array $config): array
    {
        $botToken = $config['bot_token'] ?? null;
        $teamId = $config['team_id'] ?? null;
        $bindingId = $config['binding_id'] ?? 'slack_knowledge_default';
        $channelIds = array_filter(array_map('trim', explode(',', $config['channel_ids'] ?? '')));
        $minLength = (int) ($config['min_length'] ?? 50);

        if (! $botToken || ! $teamId || $channelIds === []) {
            Log::warning('SlackChannelKnowledgeConnector: Missing bot_token, team_id, or channel_ids', [
                'binding_id' => $bindingId,
            ]);

            return [];
        }

        $lastSync = $this->getLastSyncAt($bindingId) ?? now()->subDays(7);
        $syncTime = now();
        $ingested = 0;

        foreach ($channelIds as $channelId) {
            try {
                $channelName = $this->getChannelName($channelId, $botToken);
                $ingested += $this->pollChannel($channelId, $channelName, $botToken, $teamId, $lastSync, $minLength);
            } catch (\Throwable $e) {
                Log::error('SlackChannelKnowledgeConnector: Error polling channel', [
                    'channel_id' => $channelId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->setLastSyncAt($bindingId, $syncTime);

        Log::info('SlackChannelKnowledgeConnector: Sync complete', [
            'binding_id' => $bindingId,
            'ingested' => $ingested,
        ]);

        return [];
    }

    private function pollChannel(
        string $channelId,
        string $channelName,
        string $botToken,
        string $teamId,
        Carbon $lastSync,
        int $minLength,
    ): int {
        $ingested = 0;
        $cursor = null;

        do {
            $params = [
                'channel' => $channelId,
                'oldest' => (string) $lastSync->timestamp,
                'limit' => 200,
            ];

            if ($cursor) {
                $params['cursor'] = $cursor;
            }

            $response = Http::timeout(30)
                ->withToken($botToken)
                ->get(self::SLACK_HISTORY_ENDPOINT, $params);

            if (! $response->successful()) {
                break;
            }

            $data = $response->json();

            if (! ($data['ok'] ?? false)) {
                Log::warning('SlackChannelKnowledgeConnector: API error', [
                    'channel_id' => $channelId,
                    'error' => $data['error'] ?? 'unknown',
                ]);
                break;
            }

            foreach ($data['messages'] ?? [] as $message) {
                $text = $message['text'] ?? '';
                $ts = $message['ts'] ?? '';
                $threadTs = $message['thread_ts'] ?? null;
                $replyCount = $message['reply_count'] ?? 0;

                // Skip bot messages and short messages
                if (($message['subtype'] ?? '') !== '' || strlen($text) < $minLength) {
                    continue;
                }

                // For thread root messages with replies, fetch the full thread
                if ($replyCount > 0 && $threadTs) {
                    $threadContent = $this->fetchThread($channelId, $threadTs, $botToken);
                    if ($threadContent !== '' && strlen($threadContent) >= $minLength) {
                        $this->ingestMessage(
                            teamId: $teamId,
                            content: $threadContent,
                            channelName: $channelName,
                            channelId: $channelId,
                            ts: $threadTs,
                            isThread: true,
                        );
                        $ingested++;
                    }
                } elseif ($threadTs === null) {
                    // Standalone message (not part of a thread)
                    $this->ingestMessage(
                        teamId: $teamId,
                        content: $text,
                        channelName: $channelName,
                        channelId: $channelId,
                        ts: $ts,
                        isThread: false,
                    );
                    $ingested++;
                }
            }

            $cursor = $data['response_metadata']['next_cursor'] ?? null;
        } while ($cursor);

        return $ingested;
    }

    private function fetchThread(string $channelId, string $threadTs, string $botToken): string
    {
        $response = Http::timeout(30)
            ->withToken($botToken)
            ->get(self::SLACK_REPLIES_ENDPOINT, [
                'channel' => $channelId,
                'ts' => $threadTs,
                'limit' => 50,
            ]);

        if (! $response->successful()) {
            return '';
        }

        $data = $response->json();
        $messages = $data['messages'] ?? [];

        $parts = [];
        foreach ($messages as $msg) {
            $text = $msg['text'] ?? '';
            if (trim($text) !== '' && ($msg['subtype'] ?? '') === '') {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }

    private function ingestMessage(
        string $teamId,
        string $content,
        string $channelName,
        string $channelId,
        string $ts,
        bool $isThread,
    ): void {
        $type = $isThread ? 'thread' : 'message';
        $timestamp = Carbon::createFromTimestamp((float) $ts)->toDateTimeString();
        $title = "Slack #{$channelName} {$type} — {$timestamp}";
        $sourceUrl = "https://slack.com/archives/{$channelId}/p".str_replace('.', '', $ts);

        $this->ingestAction->execute(
            teamId: $teamId,
            title: $title,
            content: $content,
            sourceUrl: $sourceUrl,
            sourceName: 'slack',
        );
    }

    private function getChannelName(string $channelId, string $botToken): string
    {
        try {
            $response = Http::timeout(10)
                ->withToken($botToken)
                ->get(self::SLACK_INFO_ENDPOINT, ['channel' => $channelId]);

            return $response->json('channel.name') ?? $channelId;
        } catch (\Throwable) {
            return $channelId;
        }
    }
}
