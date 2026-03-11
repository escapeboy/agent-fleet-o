<?php

namespace App\Domain\Chatbot\Actions;

use App\Domain\Chatbot\Models\Chatbot;

class DeleteChatbotAction
{
    public function execute(Chatbot $chatbot): void
    {
        // Revoke all active tokens immediately
        $chatbot->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);

        // Deactivate all channels
        $chatbot->channels()->update(['is_active' => false]);

        // Soft-delete the backing Agent only if it was auto-created
        if ($chatbot->agent_is_dedicated && $chatbot->agent) {
            $chatbot->agent->delete();
        }

        $chatbot->delete();
    }
}
