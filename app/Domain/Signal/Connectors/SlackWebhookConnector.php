<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;

/**
 * Slack Events API webhook connector.
 *
 * Receives push events from Slack via HTTP Events API.
 * Signature verification uses HMAC-SHA256 with the Slack signing secret.
 *
 * Config expects:
 *   'payload'      => array   (parsed Slack event payload)
 *   'default_tags' => string[]
 *
 * Supported event types: message.channels, message.groups, app_mention, reaction_added
 */
class SlackWebhookConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $payload = $config['payload'] ?? [];

        if (empty($payload)) {
            return [];
        }

        $event = $payload['event'] ?? [];

        if (empty($event)) {
            return [];
        }

        $eventType = $event['type'] ?? '';
        $defaultTags = $config['default_tags'] ?? [];

        $signal = match (true) {
            in_array($eventType, ['message', 'message.channels', 'message.groups'], true) => $this->handleMessage($event, $payload, $defaultTags),
            $eventType === 'app_mention' => $this->handleMention($event, $payload, $defaultTags),
            $eventType === 'reaction_added' => $this->handleReaction($event, $payload, $defaultTags),
            default => null,
        };

        return $signal ? [$signal] : [];
    }

    public function supports(string $driver): bool
    {
        return $driver === 'slack';
    }

    /**
     * Verify Slack request signature.
     *
     * Signature base: "v0:{X-Slack-Request-Timestamp}:{raw_body}"
     * Replay window: 5 minutes.
     */
    public static function validateSignature(string $rawBody, string $timestamp, string $signature, string $secret): bool
    {
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $baseString = "v0:{$timestamp}:{$rawBody}";
        $expected = 'v0='.hash_hmac('sha256', $baseString, $secret);

        return hash_equals($expected, $signature);
    }

    private function handleMessage(array $event, array $payload, array $defaultTags): ?Signal
    {
        // Skip bot messages, message edits/deletes
        if (isset($event['bot_id']) || isset($event['subtype'])) {
            return null;
        }

        $text = $event['text'] ?? '';
        $userId = $event['user'] ?? null;
        $channelId = $event['channel'] ?? null;
        $ts = $event['ts'] ?? null;
        $teamId = $payload['team_id'] ?? null;

        if (! $channelId) {
            return null;
        }

        return $this->ingestAction->execute(
            sourceType: 'slack',
            sourceIdentifier: "slack:{$channelId}",
            sourceNativeId: $ts ? "slack:{$channelId}:{$ts}" : null,
            payload: [
                'event_type' => 'message',
                'text' => $text,
                'user_id' => $userId,
                'channel_id' => $channelId,
                'team_id' => $teamId,
                'ts' => $ts,
                'thread_ts' => $event['thread_ts'] ?? null,
            ],
            tags: array_values(array_unique(
                array_merge(['slack', 'slack_message'], $defaultTags),
            )),
        );
    }

    private function handleMention(array $event, array $payload, array $defaultTags): ?Signal
    {
        $text = $event['text'] ?? '';
        $userId = $event['user'] ?? null;
        $channelId = $event['channel'] ?? null;
        $ts = $event['ts'] ?? null;
        $teamId = $payload['team_id'] ?? null;

        if (! $channelId) {
            return null;
        }

        return $this->ingestAction->execute(
            sourceType: 'slack',
            sourceIdentifier: "slack:{$channelId}",
            sourceNativeId: $ts ? "slack:{$channelId}:{$ts}:mention" : null,
            payload: [
                'event_type' => 'app_mention',
                'text' => $text,
                'user_id' => $userId,
                'channel_id' => $channelId,
                'team_id' => $teamId,
                'ts' => $ts,
            ],
            tags: array_values(array_unique(
                array_merge(['slack', 'slack_mention'], $defaultTags),
            )),
        );
    }

    private function handleReaction(array $event, array $payload, array $defaultTags): ?Signal
    {
        $reaction = $event['reaction'] ?? '';
        $userId = $event['user'] ?? null;
        $item = $event['item'] ?? [];
        $channelId = $item['channel'] ?? null;
        $ts = $item['ts'] ?? null;
        $teamId = $payload['team_id'] ?? null;

        return $this->ingestAction->execute(
            sourceType: 'slack',
            sourceIdentifier: "slack:{$channelId}",
            sourceNativeId: $ts ? "slack:{$channelId}:{$ts}:reaction:{$reaction}:{$userId}" : null,
            payload: [
                'event_type' => 'reaction_added',
                'reaction' => $reaction,
                'user_id' => $userId,
                'channel_id' => $channelId,
                'team_id' => $teamId,
                'item_ts' => $ts,
            ],
            tags: array_values(array_unique(
                array_merge(['slack', 'slack_reaction', "reaction:{$reaction}"], $defaultTags),
            )),
        );
    }
}
