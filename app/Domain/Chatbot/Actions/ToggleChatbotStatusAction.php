<?php

namespace App\Domain\Chatbot\Actions;

use App\Domain\Chatbot\Enums\ChatbotStatus;
use App\Domain\Chatbot\Models\Chatbot;

class ToggleChatbotStatusAction
{
    public function execute(Chatbot $chatbot): Chatbot
    {
        $newStatus = $chatbot->status === ChatbotStatus::Active
            ? ChatbotStatus::Inactive
            : ChatbotStatus::Active;

        $chatbot->update(['status' => $newStatus]);

        return $chatbot;
    }

    public function activate(Chatbot $chatbot): Chatbot
    {
        $chatbot->update(['status' => ChatbotStatus::Active]);

        return $chatbot;
    }

    public function deactivate(Chatbot $chatbot): Chatbot
    {
        $chatbot->update(['status' => ChatbotStatus::Inactive]);

        return $chatbot;
    }
}
