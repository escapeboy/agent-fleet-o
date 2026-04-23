<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Events;

use App\Domain\AgentChatProtocol\Models\AgentChatMessage;
use Illuminate\Foundation\Events\Dispatchable;

final class StructuredResponseReceived
{
    use Dispatchable;

    public function __construct(public AgentChatMessage $message) {}
}
