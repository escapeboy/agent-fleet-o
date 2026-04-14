<?php

namespace App\Domain\Signal\Services;

use App\Domain\Signal\Enums\SignalStatus;

class SignalStatusTransitionMap
{
    /** @var array<string, string[]> */
    private const TRANSITIONS = [
        SignalStatus::Received->value => [
            SignalStatus::Triaged->value,
            SignalStatus::InProgress->value,
            SignalStatus::DelegatedToAgent->value,
            SignalStatus::Dismissed->value,
        ],
        SignalStatus::Triaged->value => [
            SignalStatus::InProgress->value,
            SignalStatus::DelegatedToAgent->value,
            SignalStatus::Dismissed->value,
        ],
        SignalStatus::InProgress->value => [
            SignalStatus::Review->value,
            SignalStatus::Resolved->value,
            SignalStatus::DelegatedToAgent->value,
            SignalStatus::Dismissed->value,
        ],
        SignalStatus::DelegatedToAgent->value => [
            SignalStatus::AgentFixing->value,
            SignalStatus::InProgress->value,
        ],
        SignalStatus::AgentFixing->value => [
            SignalStatus::Review->value,
            SignalStatus::InProgress->value,
        ],
        SignalStatus::Review->value => [
            SignalStatus::Resolved->value,
            SignalStatus::InProgress->value,
        ],
        SignalStatus::Resolved->value => [],
        SignalStatus::Dismissed->value => [],
    ];

    public function canTransition(SignalStatus $from, SignalStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /** @return SignalStatus[] */
    public function allowedTransitionsFrom(SignalStatus $status): array
    {
        return array_map(
            fn (string $v) => SignalStatus::from($v),
            self::TRANSITIONS[$status->value] ?? [],
        );
    }
}
