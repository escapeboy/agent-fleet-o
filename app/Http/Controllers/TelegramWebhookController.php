<?php

namespace App\Http\Controllers;

use App\Domain\Telegram\Jobs\ProcessTelegramMessageJob;
use App\Domain\Telegram\Models\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TelegramWebhookController extends Controller
{
    /**
     * Handle an inbound Telegram webhook push.
     * Each team's bot is registered at a unique team-scoped URL to avoid cross-team message routing.
     * Returns 200 immediately; processing happens async in ProcessTelegramMessageJob.
     */
    public function handle(Request $request, string $teamId): Response
    {
        // Find active bot for this team
        $bot = TelegramBot::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', 'active')
            ->first();

        if (! $bot) {
            return response('', 200); // Return 200 to silence Telegram retries
        }

        // Verify secret token header
        if ($bot->webhook_secret) {
            $secret = $request->header('X-Telegram-Bot-Api-Secret-Token');
            if ($secret !== $bot->webhook_secret) {
                return response('', 200); // Silent fail to prevent enumeration
            }
        }

        $update = $request->json()->all();
        $message = $update['message'] ?? $update['callback_query']['message'] ?? null;

        if (! $message) {
            return response('', 200);
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = $message['text'] ?? $update['callback_query']['data'] ?? '';
        $username = ($update['callback_query']['from'] ?? $message['from'] ?? [])['username'] ?? null;

        if ($chatId && $text !== '') {
            ProcessTelegramMessageJob::dispatch($bot->id, $chatId, $text, $username);
        }

        return response('', 200);
    }
}
