<?php

namespace App\Domain\GitRepository\Enums;

/**
 * Per-repository commit discipline (Aider-inspired).
 *
 * - Off: caller's commit message is used verbatim (today's behavior).
 * - Atomic: each mutation's commit message is rewritten by a weak model into
 *   Conventional Commits format. Wraps the underlying GitClientInterface.
 */
enum CommitDiscipline: string
{
    case Off = 'off';
    case Atomic = 'atomic';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off (caller-provided messages)',
            self::Atomic => 'Atomic (Conventional Commits via weak model)',
        };
    }
}
