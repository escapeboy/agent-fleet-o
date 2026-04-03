<?php

namespace App\Domain\Tool\Enums;

enum ApprovalTimeoutAction: string
{
    case Deny = 'deny';
    case Skip = 'skip';
    case Allow = 'allow';

    public function label(): string
    {
        return match ($this) {
            self::Deny => 'Deny (fail the step)',
            self::Skip => 'Skip (continue without tool)',
            self::Allow => 'Allow (proceed anyway)',
        };
    }
}
