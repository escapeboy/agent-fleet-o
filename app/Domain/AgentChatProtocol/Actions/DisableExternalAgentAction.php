<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Actions;

use App\Domain\AgentChatProtocol\Enums\ExternalAgentStatus;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;

class DisableExternalAgentAction
{
    public function execute(ExternalAgent $externalAgent, bool $softDelete = false): ExternalAgent
    {
        $externalAgent->forceFill(['status' => ExternalAgentStatus::Disabled])->save();

        if ($softDelete) {
            $externalAgent->delete();
        }

        return $externalAgent;
    }
}
