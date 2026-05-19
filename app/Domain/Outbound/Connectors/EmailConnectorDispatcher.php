<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Outbound\Services\OutboundCredentialResolver;

/**
 * Resolves the concrete email connector for a proposal at send time.
 *
 * The `email` outbound channel can be backed by either raw SMTP or the Resend
 * API. The team's choice is data-driven: the `provider` key on their `email`
 * connector config selects the driver ('smtp' is the default). This keeps both
 * connectors as independent parallel drivers with no shared mutable state.
 */
class EmailConnectorDispatcher implements OutboundConnectorInterface
{
    public function __construct(
        private readonly OutboundCredentialResolver $resolver,
    ) {}

    public function send(OutboundProposal $proposal): OutboundAction
    {
        $creds = $this->resolver->getDbConfig('email', $proposal->team_id)?->credentials ?? [];
        $provider = $creds['provider'] ?? 'smtp';

        $connector = $provider === 'resend'
            ? app(ResendEmailConnector::class)
            : app(SmtpEmailConnector::class);

        return $connector->send($proposal);
    }

    public function supports(string $channel): bool
    {
        return $channel === 'email';
    }
}
