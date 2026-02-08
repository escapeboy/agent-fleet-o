<?php

namespace App\Domain\Outbound\Contracts;

use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;

interface OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction;

    public function supports(string $channel): bool;
}
