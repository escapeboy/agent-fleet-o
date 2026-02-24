<?php

namespace App\Domain\Telegram\Actions;

use App\Domain\Assistant\Actions\SendAssistantMessageAction;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramChatBinding;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ProcessTelegramMessageAction
{
    public function __construct(
        private readonly SendAssistantMessageAction $sendAssistantMessage,
        private readonly SendTelegramReplyAction $sendReply,
    ) {}

    /**
     * Process an inbound Telegram message: find/create conversation binding,
     * run the assistant, and send the reply.
     */
    public function execute(TelegramBot $bot, string $chatId, string $text, ?string $username = null): void
    {
        // Handle special commands
        $reply = $this->handleCommand($text, $bot);
        if ($reply !== null) {
            $this->sendReply->execute($bot->bot_token, $chatId, $reply);

            return;
        }

        // Find or create conversation binding for this chat
        $binding = TelegramChatBinding::withoutGlobalScopes()
            ->where('team_id', $bot->team_id)
            ->where('chat_id', $chatId)
            ->first();

        if (! $binding) {
            $binding = $this->createBinding($bot, $chatId, $username);
        }

        // Get or create conversation
        /** @var \App\Domain\Assistant\Models\AssistantConversation|null $conversation */
        $conversation = $binding->conversation;
        if (! $conversation) {
            $conversation = $this->createConversation($bot, $username);
            $binding->update(['conversation_id' => $conversation->id]);
            $binding->refresh();
        }

        // Resolve user: prefer bound user, fall back to team owner
        /** @var \App\Models\User|null $user */
        $user = $binding->user ?? $this->resolveTeamUser($bot->team_id);
        if (! $user) {
            Log::warning('ProcessTelegramMessageAction: no user found', ['team_id' => $bot->team_id]);
            $this->sendReply->execute($bot->bot_token, $chatId, 'Error: no team user found.');

            return;
        }

        // Send "processing" indicator for potentially slow LLM responses
        $this->sendReply->execute($bot->bot_token, $chatId, '⏳ Processing...');

        try {
            $response = $this->sendAssistantMessage->execute(
                conversation: $conversation,
                userMessage: $text,
                user: $user,
                contextType: 'telegram',
                contextId: $chatId,
            );

            $replyText = $response->content ?: 'I could not process that request.';
        } catch (\Throwable $e) {
            Log::error('ProcessTelegramMessageAction: assistant error', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            $replyText = 'I encountered an error processing your message. Please try again.';
        }

        // Update last message timestamp on bot
        $bot->update(['last_message_at' => now()]);

        $this->sendReply->execute($bot->bot_token, $chatId, $replyText);
    }

    private function handleCommand(string $text, TelegramBot $bot): ?string
    {
        $command = strtolower(trim($text));

        if (str_starts_with($command, '/start')) {
            $botName = $bot->bot_name ?? 'Agent Fleet Bot';

            return "<b>Welcome to {$botName}!</b>\n\nI'm connected to your Agent Fleet workspace. You can:\n• Chat with your AI assistant\n• Ask about your projects and experiments\n• Get status updates\n\nType /help for more commands.";
        }

        if ($command === '/help') {
            return "<b>Available commands:</b>\n\n/start — Show welcome message\n/help — Show this help\n/status — Show last 3 project runs\n\nOr just type any message to chat with your assistant.";
        }

        if ($command === '/status') {
            $runs = \App\Domain\Project\Models\ProjectRun::withoutGlobalScopes()
                ->where('project_runs.id', function ($query) use ($bot) {
                    $query->from('project_runs')
                        ->whereExists(function ($q) use ($bot) {
                            $q->from('projects')
                                ->whereColumn('projects.id', 'project_runs.project_id')
                                ->where('projects.team_id', $bot->team_id);
                        })
                        ->select('id');
                })
                ->with('project')
                ->latest()
                ->limit(3)
                ->get();

            if ($runs->isEmpty()) {
                return 'No recent project runs found.';
            }

            $lines = ["<b>Last 3 project runs:</b>\n"];
            foreach ($runs as $run) {
                /** @var \App\Domain\Project\Models\ProjectRun $run */
                $status = $run->status instanceof \BackedEnum ? $run->status->value : (string) $run->status;
                $lines[] = "• <b>{$run->project?->title}</b> — {$status} ({$run->created_at->diffForHumans()})";
            }

            return implode("\n", $lines);
        }

        return null;
    }

    private function createBinding(TelegramBot $bot, string $chatId, ?string $username): TelegramChatBinding
    {
        return TelegramChatBinding::create([
            'team_id' => $bot->team_id,
            'chat_id' => $chatId,
            'user_id' => null,
            'conversation_id' => null,
        ]);
    }

    private function createConversation(TelegramBot $bot, ?string $username): AssistantConversation
    {
        return AssistantConversation::create([
            'team_id' => $bot->team_id,
            'user_id' => null,
            'title' => 'Telegram chat'.($username ? " (@{$username})" : ''),
            'context_type' => 'telegram',
            'context_id' => null,
        ]);
    }

    private function resolveTeamUser(string $teamId): ?User
    {
        /** @var User|null $owner */
        $owner = \App\Domain\Shared\Models\Team::find($teamId)?->owner;

        return $owner;
    }
}
