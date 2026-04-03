<?php

namespace App\Domain\Tool\Enums;

enum ToolApprovalMode: string
{
    case Auto = 'auto';
    case Ask = 'ask';
    case Deny = 'deny';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Auto-approve',
            self::Ask => 'Require approval',
            self::Deny => 'Always deny',
        };
    }
}
