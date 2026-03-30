<?php

namespace App\Domain\VoiceSession\Enums;

enum VoiceSessionStatus: string
{
    /** Session created but no participant has joined yet. */
    case Pending = 'pending';

    /** At least one participant is connected and audio is flowing. */
    case Active = 'active';

    /** Session completed normally. */
    case Ended = 'ended';

    /** Voice pipeline error — session could not proceed. */
    case Failed = 'failed';
}
