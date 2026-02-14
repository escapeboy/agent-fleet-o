<?php

namespace App\Domain\Outbound\Actions;

use App\Domain\Outbound\Connectors\DummyConnector;
use App\Domain\Outbound\Connectors\SlackConnector;
use App\Domain\Outbound\Connectors\SmtpEmailConnector;
use App\Domain\Outbound\Connectors\TelegramConnector;
use App\Domain\Outbound\Connectors\WebhookOutboundConnector;
use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Exceptions\BlacklistedException;
use App\Domain\Outbound\Exceptions\RateLimitExceededException;
use App\Domain\Outbound\Middleware\ChannelRateLimit;
use App\Domain\Outbound\Middleware\TargetRateLimit;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;

class SendOutboundAction
{
    private array $connectors;

    public function __construct(
        private readonly CheckBlacklistAction $checkBlacklist,
        private readonly ChannelRateLimit $channelRateLimit,
        private readonly TargetRateLimit $targetRateLimit,
    ) {
        $this->connectors = [
            new SmtpEmailConnector,
            new TelegramConnector,
            new SlackConnector,
            new WebhookOutboundConnector,
            new DummyConnector,  // Fallback — must be last
        ];
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

        $channel = $proposal->channel->value;
        $connector = $this->resolveConnector($channel);

        return $connector->send($proposal);
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
