<?php

namespace App\Domain\Chatbot\Enums;

enum ChatbotType: string
{
    case HelpBot = 'help_bot';
    case SupportAssistant = 'support_assistant';
    case DeveloperAssistant = 'developer_assistant';
    case Custom = 'custom';

    public function label(): string
    {
        return match($this) {
            self::HelpBot => 'Help Bot',
            self::SupportAssistant => 'Support Assistant',
            self::DeveloperAssistant => 'Developer Assistant',
            self::Custom => 'Custom',
        };
    }
}
