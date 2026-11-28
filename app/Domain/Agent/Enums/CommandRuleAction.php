<?php

namespace App\Domain\Agent\Enums;

/**
 * What happens to a coding-agent shell command that matches a CommandRule.
 * When rules of the same length tie, the most severe action wins.
 */
enum CommandRuleAction: string
{
    case Allow = 'allow';
    case Ask = 'ask';
    case Block = 'block';

    public function severity(): int
    {
        return match ($this) {
            self::Allow => 0,
            self::Ask => 1,
            self::Block => 2,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Allow => 'Allow',
            self::Ask => 'Ask',
            self::Block => 'Block',
        };
    }

    public function atLeast(self $other): self
    {
        return $other->severity() > $this->severity() ? $other : $this;
    }
}
