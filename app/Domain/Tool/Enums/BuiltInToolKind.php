<?php

namespace App\Domain\Tool\Enums;

enum BuiltInToolKind: string
{
    case Bash = 'bash';
    case Filesystem = 'filesystem';
    case Browser = 'browser';
    case Ssh = 'ssh';
    case BrowserRelay = 'browser_relay';
    case ComputerUse = 'computer_use';
    case BrowserUseCloud = 'browser_use_cloud';
    case ExecuteCode = 'execute_code';
    case BrowserHarness = 'browser_harness';

    public function label(): string
    {
        return match ($this) {
            self::Bash => 'Bash / Shell',
            self::Filesystem => 'Filesystem',
            self::Browser => 'Browser',
            self::Ssh => 'SSH Remote',
            self::BrowserRelay => 'Browser Relay (via relay agent)',
            self::ComputerUse => 'Computer Use (desktop automation)',
            self::BrowserUseCloud => 'Browser Use Cloud (cloud.browser-use.com)',
            self::ExecuteCode => 'Execute Code',
            self::BrowserHarness => 'Browser Harness (self-healing CDP)',
        };
    }
}
