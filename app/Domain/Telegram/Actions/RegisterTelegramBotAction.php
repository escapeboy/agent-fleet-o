<?php

namespace App\Domain\Telegram\Actions;

use App\Domain\Telegram\Models\TelegramBot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegisterTelegramBotAction
{
    /**
     * Validate a bot token via getMe, then create or update TelegramBot for the team.
     */
    public function execute(
        string $teamId,
        string $botToken,
        string $routingMode = 'assistant',
        ?string $defaultProjectId = null,
    ): TelegramBot {
        // Validate token by calling getMe
        $response = Http::timeout(10)->get(
            "https://api.telegram.org/bot{$botToken}/getMe",
        );

        if (! $response->successful() || ! $response->json('ok')) {
            $description = $response->json('description', 'Invalid bot token');
            throw ValidationException::withMessages(['bot_token' => $description]);
        }

        $botInfo = $response->json('result');

        // Auto-generate a cryptographically random webhook secret if the team doesn't already have one.
        // This secret is verified on every inbound webhook to prevent unauthenticated message injection.
        $existingSecret = TelegramBot::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->value('webhook_secret');

        return TelegramBot::updateOrCreate(
            ['team_id' => $teamId],
            [
                'bot_token' => $botToken,
                'bot_username' => $botInfo['username'] ?? null,
                'bot_name' => $botInfo['first_name'] ?? null,
                'routing_mode' => $routingMode,
                'default_project_id' => $defaultProjectId,
                'webhook_secret' => $existingSecret ?? Str::random(32),
                'status' => 'active',
                'last_error' => null,
            ],
        );
    }
}
