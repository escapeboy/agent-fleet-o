<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\Models\TeamToolActivation;
use App\Domain\Tool\Models\Tool;

class ActivatePlatformToolAction
{
    public function execute(
        Tool $tool,
        string $teamId,
        array $credentialOverrides = [],
        array $configOverrides = [],
    ): TeamToolActivation {
        if (! $tool->isPlatformTool()) {
            throw new \RuntimeException('This action is only for platform tools.');
        }

        return TeamToolActivation::updateOrCreate(
            ['team_id' => $teamId, 'tool_id' => $tool->id],
            [
                'status' => 'active',
                'credential_overrides' => $credentialOverrides,
                'config_overrides' => $configOverrides,
                'activated_at' => now(),
            ],
        );
    }
}
