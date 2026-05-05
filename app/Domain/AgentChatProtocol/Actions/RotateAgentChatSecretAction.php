<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Actions;

use App\Domain\Agent\Models\Agent;
use Illuminate\Support\Str;

class RotateAgentChatSecretAction
{
    public function execute(Agent $agent): string
    {
        $secret = Str::random(48);
        $agent->forceFill(['chat_protocol_secret' => $secret])->save();

        return $secret;
    }
}
