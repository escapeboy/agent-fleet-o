<?php

namespace App\Domain\Tool\Enums;

enum BuiltInToolKind: string
{
    case Bash = 'bash';
    case Filesystem = 'filesystem';
    case Browser = 'browser';

    public function label(): string
    {
        return match ($this) {
            self::Bash => 'Bash / Shell',
            self::Filesystem => 'Filesystem',
            self::Browser => 'Browser',
        };
    }
}
