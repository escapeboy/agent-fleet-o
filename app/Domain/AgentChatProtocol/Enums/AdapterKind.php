<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Enums;

enum AdapterKind: string
{
    case Http = 'http';
    case AgentverseMailbox = 'agentverse_mailbox';
    case AgentverseProxy = 'agentverse_proxy';

    public function isAgentverse(): bool
    {
        return $this === self::AgentverseMailbox || $this === self::AgentverseProxy;
    }
}
