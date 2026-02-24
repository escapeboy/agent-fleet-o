<?php

namespace App\Mcp\Tools\Telegram;

use App\Domain\Telegram\Actions\RegisterTelegramBotAction;
use App\Domain\Telegram\Models\TelegramBot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class TelegramBotTool extends Tool
{
    protected string $name = 'telegram_bot_manage';

    protected string $description = 'Get status, configure, or disconnect the Telegram bot for the current team.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: status | register | disconnect')
                ->enum(['status', 'register', 'disconnect'])
                ->required(),
            'bot_token' => $schema->string()
                ->description('Bot token from BotFather (required for register)'),
            'routing_mode' => $schema->string()
                ->description('Routing mode: assistant | project | trigger_rules (default: assistant)')
                ->enum(['assistant', 'project', 'trigger_rules'])
                ->default('assistant'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $teamId = $user?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $action = $request->get('action', 'status');

        if ($action === 'status') {
            $bot = TelegramBot::withoutGlobalScopes()->where('team_id', $teamId)->first();

            if (! $bot) {
                return Response::text(json_encode([
                    'connected' => false,
                    'message' => 'No Telegram bot configured. Use action=register with a bot_token.',
                ]));
            }

            return Response::text(json_encode([
                'connected' => true,
                'bot_username' => $bot->bot_username,
                'bot_name' => $bot->bot_name,
                'routing_mode' => $bot->routing_mode,
                'status' => $bot->status,
                'last_message_at' => $bot->last_message_at?->diffForHumans(),
            ]));
        }

        if ($action === 'register') {
            $botToken = $request->get('bot_token');
            if (! $botToken) {
                return Response::error('bot_token is required for register action.');
            }

            $bot = app(RegisterTelegramBotAction::class)->execute(
                teamId: $teamId,
                botToken: $botToken,
                routingMode: $request->get('routing_mode', 'assistant'),
            );

            return Response::text(json_encode([
                'success' => true,
                'bot_username' => $bot->bot_username,
                'bot_name' => $bot->bot_name,
                'message' => "Telegram bot @{$bot->bot_username} connected.",
            ]));
        }

        if ($action === 'disconnect') {
            TelegramBot::withoutGlobalScopes()->where('team_id', $teamId)->delete();

            return Response::text('Telegram bot disconnected.');
        }

        return Response::error("Unknown action: {$action}");
    }
}
