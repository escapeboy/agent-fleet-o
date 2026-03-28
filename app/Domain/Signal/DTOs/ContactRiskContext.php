<?php

namespace App\Domain\Signal\DTOs;

use App\Domain\Shared\Models\ContactIdentity;
use App\Infrastructure\Security\DTOs\IpReputationResult;

readonly class ContactRiskContext
{
    public function __construct(
        public ContactIdentity $contact,
        public ?IpReputationResult $ipReputation,
        /** @var array<int, mixed> Recent signals for this contact (last 30 days) */
        public array $recentSignals,
        public int $signalCount,
        /** @var string[] Channel types (e.g. ['email', 'telegram']) */
        public array $channelTypes,
        public bool $hasVerifiedChannel,
    ) {}
}
