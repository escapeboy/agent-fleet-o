<?php

namespace App\Domain\Chatbot\Enums;

enum ChannelType: string
{
    case WebWidget = 'web_widget';
    case Api = 'api';
    case Telegram = 'telegram';
    case Slack = 'slack';

    public function label(): string
    {
        return match($this) {
            self::WebWidget => 'Web Widget',
            self::Api => 'API',
            self::Telegram => 'Telegram',
            self::Slack => 'Slack',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::WebWidget => 'chat-bubble-left-right',
            self::Api => 'code-bracket',
            self::Telegram => 'paper-airplane',
            self::Slack => 'hashtag',
        };
    }
}
