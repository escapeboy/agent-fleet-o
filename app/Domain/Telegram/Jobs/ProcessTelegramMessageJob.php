<?php

namespace App\Domain\Telegram\Jobs;

use App\Domain\Telegram\Actions\ProcessTelegramMessageAction;
use App\Domain\Telegram\Models\TelegramBot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(
        public readonly string $botId,
        public readonly string $chatId,
        public readonly string $text,
        public readonly ?string $username = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(ProcessTelegramMessageAction $action): void
    {
        $bot = TelegramBot::withoutGlobalScopes()->find($this->botId);

        if (! $bot || ! $bot->isActive()) {
            return;
        }

        $action->execute($bot, $this->chatId, $this->text, $this->username);
    }
}
