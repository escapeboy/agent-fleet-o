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
            // T1 auto-merge path (workflow's bitbucket_pr_merge node merges the PR
            // before any human review step runs): the Bitbucket webhook fires
            // CloseBugReportOnPrMergeListener while the Signal is still
            // DelegatedToAgent, so the listener needs this edge to transition to
            // Resolved without throwing InvalidSignalTransitionException.
            SignalStatus::Resolved->value,
        ],
        SignalStatus::AgentFixing->value => [
            SignalStatus::Review->value,
            SignalStatus::InProgress->value,
            // Reporter follow-up may re-engage the agent loop while the previous
            // attempt is still mid-fix.
            SignalStatus::DelegatedToAgent->value,
            // Same T1 auto-merge consideration as DelegatedToAgent — the webhook
            // may arrive mid-fix when the merge happens before status flips back
            // to Review.
            SignalStatus::Resolved->value,
        ],
        SignalStatus::Review->value => [
            SignalStatus::Resolved->value,
            SignalStatus::InProgress->value,
            // Reporter follow-up may re-engage the agent loop after the previous
            // attempt landed in Review without resolving the bug.
            SignalStatus::DelegatedToAgent->value,
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
