<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Enums;

enum AdapterKind: string
{
    case Http = 'http';
    case AgentverseMailbox = 'agentverse_mailbox';
    case AgentverseProxy = 'agentverse_proxy';
    case A2a = 'a2a';

    public function isAgentverse(): bool
    {
        return $this === self::AgentverseMailbox || $this === self::AgentverseProxy;
    }

    public function isA2a(): bool
    {
        return $this === self::A2a;
    }
}
