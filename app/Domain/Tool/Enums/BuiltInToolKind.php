<?php

namespace App\Domain\Tool\Enums;

enum BuiltInToolKind: string
{
    case Bash = 'bash';
    case Filesystem = 'filesystem';
    case Browser = 'browser';
    case Ssh = 'ssh';
    case BrowserRelay = 'browser_relay';

    public function label(): string
    {
        return match ($this) {
            self::Bash => 'Bash / Shell',
            self::Filesystem => 'Filesystem',
            self::Browser => 'Browser',
            self::Ssh => 'SSH Remote',
            self::BrowserRelay => 'Browser Relay (via relay agent)',
        };
    }
}
