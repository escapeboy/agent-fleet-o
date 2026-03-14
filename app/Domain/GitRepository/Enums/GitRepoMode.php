<?php

namespace App\Domain\GitRepository\Enums;

enum GitRepoMode: string
{
    case ApiOnly = 'api_only';
    case Sandbox = 'sandbox';
    case Bridge = 'bridge';

    public function label(): string
    {
        return match ($this) {
            self::ApiOnly => 'API Only',
            self::Sandbox => 'Ephemeral Sandbox',
            self::Bridge => 'Bridge (Local)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ApiOnly => 'Read and write files via GitHub/GitLab REST API. No cloning required. Best for cloud agents.',
            self::Sandbox => 'Spin up an ephemeral compute container, clone the repo, run operations, then destroy. Supports test execution.',
            self::Bridge => 'Route git operations through the local Bridge daemon. Best for self-hosted or local repositories.',
        };
    }
}
