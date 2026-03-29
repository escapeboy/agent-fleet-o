<?php

namespace App\Domain\VoiceSession\Actions;

use App\Domain\VoiceSession\Enums\VoiceSessionStatus;
use App\Domain\VoiceSession\Exceptions\VoiceSessionException;
use App\Domain\VoiceSession\Models\VoiceSession;

/**
 * Ends an active or pending voice session.
 *
 * Sets status to `ended`, records `ended_at`, and optionally merges a final transcript.
 */
class EndVoiceSessionAction
{
    /**
     * @throws VoiceSessionException If the session is already in a terminal state.
     */
    public function execute(VoiceSession $session, ?array $finalTranscript = null): VoiceSession
    {
        if (! $session->isOpen()) {
            throw VoiceSessionException::sessionNotActive($session->id);
        }

        $update = [
            'status' => VoiceSessionStatus::Ended,
            'ended_at' => now(),
        ];

        if ($finalTranscript !== null) {
            $update['transcript'] = $finalTranscript;
        }

        $session->update($update);

        return $session->refresh();
    }
}
