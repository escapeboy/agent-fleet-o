<?php

namespace App\Domain\Chatbot\Actions;

use App\Domain\Chatbot\Models\ChatbotToken;

class RevokeChatbotTokenAction
{
    public function execute(ChatbotToken $token): void
    {
        $token->revoke();
    }
}
