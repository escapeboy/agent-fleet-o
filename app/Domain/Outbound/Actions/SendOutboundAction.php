<?php

namespace App\Domain\Outbound\Actions;

use App\Domain\Outbound\Connectors\DiscordConnector;
use App\Domain\Outbound\Connectors\DummyConnector;
use App\Domain\Outbound\Connectors\GoogleChatConnector;
use App\Domain\Outbound\Connectors\MatrixConnector;
use App\Domain\Outbound\Connectors\SignalProtocolConnector;
use App\Domain\Outbound\Connectors\SlackConnector;
use App\Domain\Outbound\Connectors\SmtpEmailConnector;
use App\Domain\Outbound\Connectors\TeamsConnector;
use App\Domain\Outbound\Connectors\TelegramConnector;
use App\Domain\Outbound\Connectors\WebhookOutboundConnector;
use App\Domain\Outbound\Connectors\WhatsAppConnector;
use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Events\OutboundSending;
use App\Domain\Outbound\Events\OutboundSent;
use App\Domain\Outbound\Exceptions\BlacklistedException;
use App\Domain\Outbound\Exceptions\RateLimitExceededException;
use App\Domain\Outbound\Middleware\ChannelRateLimit;
use App\Domain\Outbound\Middleware\TargetRateLimit;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Outbound\Services\OutboundCredentialResolver;

class SendOutboundAction
{
    private array $connectors;

    public function __construct(
        private readonly CheckBlacklistAction $checkBlacklist,
        private readonly ChannelRateLimit $channelRateLimit,
        private readonly TargetRateLimit $targetRateLimit,
        private readonly OutboundCredentialResolver $credentialResolver,
    ) {
        $this->connectors = [
            new SmtpEmailConnector($this->credentialResolver),
            new TelegramConnector,
            new SlackConnector,
            new WhatsAppConnector,
            new DiscordConnector,
            new TeamsConnector,
            new GoogleChatConnector,
            new WebhookOutboundConnector,
            new SignalProtocolConnector,
            new MatrixConnector,
        ];

        // Append plugin-registered outbound connectors (tagged in service providers)
        foreach (app()->tagged('fleet.outbound.connectors.plugin') as $connector) {
            $this->connectors[] = $connector;
        }

        $this->connectors[] = new DummyConnector;  // Fallback — must be last
    }

    public function execute(OutboundProposal $proposal): OutboundAction
    {
        // Check blacklist
        $blacklistResult = $this->checkBlacklist->execute($proposal);
        if ($blacklistResult['blocked']) {
            throw new BlacklistedException($blacklistResult['reason']);
        }

        // Check channel rate limit
        if (! $this->channelRateLimit->check($proposal)) {
            throw new RateLimitExceededException(
                "Channel rate limit exceeded for {$proposal->channel->value}",
            );
        }

        // Check target rate limit
        if (! $this->targetRateLimit->check($proposal)) {
            throw new RateLimitExceededException(
                'Target rate limit exceeded — contact cooldown active',
            );
        }

        // Plugin hook: allow plugins to cancel outbound delivery
        $sending = new OutboundSending($proposal);
        event($sending);
        if ($sending->cancel) {
            throw new BlacklistedException($sending->cancelReason ?? 'Cancelled by plugin');
        }

        $channel = $proposal->channel->value;
        $connector = $this->resolveConnector($channel);

        $action = $connector->send($proposal);

        // Plugin hook: notify plugins of delivery result
        event(new OutboundSent($proposal, $action, $action->status === 'delivered'));

        return $action;
    }

    private function resolveConnector(string $channel): OutboundConnectorInterface
    {
        foreach ($this->connectors as $connector) {
            if ($connector->supports($channel)) {
                return $connector;
            }
        }

        return new DummyConnector;
    }
}
