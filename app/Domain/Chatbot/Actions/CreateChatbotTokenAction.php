<?php

namespace App\Domain\Chatbot\Actions;

use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotToken;
use Illuminate\Support\Str;

class CreateChatbotTokenAction
{
    /**
     * Generate a new API token for the chatbot.
     * Old active tokens get a 48-hour grace period on rotation.
     *
     * @return array{token: string, model: ChatbotToken}
     */
    public function execute(
        Chatbot $chatbot,
        string $name = 'Default',
        bool $rotateExisting = false,
        array $allowedOrigins = [],
    ): array {
        if ($rotateExisting) {
            // Set 48-hour expiry on all existing active tokens
            $chatbot->activeTokens()->update(['expires_at' => now()->addHours(48)]);
        }

        $plaintext = 'fq_cb_' . Str::random(48);
        $hash = hash('sha256', $plaintext);
        $prefix = substr($plaintext, 0, 12); // "fq_cb_" + 6 chars

        $token = ChatbotToken::create([
            'chatbot_id' => $chatbot->id,
            'team_id' => $chatbot->team_id,
            'name' => $name,
            'token_prefix' => $prefix,
            'token_hash' => $hash,
            'allowed_origins' => empty($allowedOrigins) ? null : $allowedOrigins,
        ]);

        return ['token' => $plaintext, 'prefix' => $prefix, 'model' => $token];
    }
}
