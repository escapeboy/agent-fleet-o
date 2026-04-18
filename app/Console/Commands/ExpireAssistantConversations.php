<?php

namespace App\Console\Commands;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Shared\Models\Team;
use Illuminate\Console\Command;

class ExpireAssistantConversations extends Command
{
    protected $signature = 'conversations:expire';

    protected $description = 'Mark assistant conversations expired based on team TTL settings';

    public function handle(): int
    {
        Team::withoutGlobalScopes()->cursor()->each(function (Team $team) {
            $ttl = $team->settings['max_session_duration_minutes'] ?? null;

            if (! $ttl || (int) $ttl <= 0) {
                return;
            }

            $cutoff = now()->subMinutes((int) $ttl);

            AssistantConversation::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->whereNull('expired_at')
                ->where(function ($q) use ($cutoff) {
                    $q->where('last_message_at', '<', $cutoff)
                        ->orWhere(function ($q2) use ($cutoff) {
                            $q2->whereNull('last_message_at')
                                ->where('created_at', '<', $cutoff);
                        });
                })
                ->update(['expired_at' => now()]);
        });

        return self::SUCCESS;
    }
}
