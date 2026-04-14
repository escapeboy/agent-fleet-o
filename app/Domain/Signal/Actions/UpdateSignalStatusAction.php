<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Events\SignalStatusChanged;
use App\Domain\Signal\Exceptions\InvalidSignalTransitionException;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\SignalStatusTransitionMap;
use App\Models\User;

class UpdateSignalStatusAction
{
    public function __construct(
        private readonly SignalStatusTransitionMap $transitionMap,
        private readonly AddSignalCommentAction $addComment,
    ) {}

    public function execute(
        Signal $signal,
        SignalStatus $newStatus,
        ?string $comment = null,
        ?User $actor = null,
    ): Signal {
        $currentStatus = $signal->status ?? SignalStatus::Received;

        if ($currentStatus !== $newStatus && ! $this->transitionMap->canTransition($currentStatus, $newStatus)) {
            throw new InvalidSignalTransitionException(
                "Cannot transition signal from {$currentStatus->value} to {$newStatus->value}."
            );
        }

        $oldStatus = $currentStatus;
        $signal->update(['status' => $newStatus]);

        if ($comment) {
            $this->addComment->execute(
                signal: $signal,
                body: $comment,
                authorType: 'human',
                userId: $actor?->id,
            );
        }

        event(new SignalStatusChanged($signal, $oldStatus, $newStatus));

        return $signal;
    }
}
