<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Support\Facades\Http;

/**
 * Telegram Bot API connector.
 *
 * Sends messages via the Telegram Bot API sendMessage endpoint.
 * Requires TELEGRAM_BOT_TOKEN in .env.
 */
class TelegramConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "telegram|{$proposal->id}");

        $existing = OutboundAction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $action = OutboundAction::create([
            'outbound_proposal_id' => $proposal->id,
            'status' => OutboundActionStatus::Sending,
            'idempotency_key' => $idempotencyKey,
            'retry_count' => 0,
        ]);

        try {
            $botToken = config('services.telegram.bot_token');
            if (!$botToken) {
                throw new \RuntimeException('Telegram bot token not configured');
            }

            $target = $proposal->target;
            $content = $proposal->content;

            $chatId = $target['chat_id'] ?? $target['id'] ?? null;
            if (!$chatId) {
                throw new \InvalidArgumentException('No chat_id in target');
            }

            $text = $content['body'] ?? $content['text'] ?? 'No content generated.';

            $response = Http::timeout(15)
                ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => $content['disable_preview'] ?? false,
                ]);

            if ($response->successful() && $response->json('ok')) {
                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => (string) $response->json('result.message_id'),
                    'response' => $response->json(),
                    'sent_at' => now(),
                ]);
            } else {
                $action->update([
                    'status' => OutboundActionStatus::Failed,
                    'response' => $response->json() ?? ['error' => $response->body()],
                    'retry_count' => $action->retry_count + 1,
                ]);
            }
        } catch (\Throwable $e) {
            $action->update([
                'status' => OutboundActionStatus::Failed,
                'response' => ['error' => $e->getMessage()],
                'retry_count' => $action->retry_count + 1,
            ]);
        }

        return $action;
    }

    public function supports(string $channel): bool
    {
        return $channel === 'telegram';
    }
}
