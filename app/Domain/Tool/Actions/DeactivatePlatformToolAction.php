<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\Models\TeamToolActivation;
use App\Domain\Tool\Models\Tool;

class DeactivatePlatformToolAction
{
    public function execute(Tool $tool, string $teamId): void
    {
        if (! $tool->isPlatformTool()) {
            throw new \RuntimeException('This action is only for platform tools.');
        }

        TeamToolActivation::where('team_id', $teamId)
            ->where('tool_id', $tool->id)
            ->update(['status' => 'disabled']);
    }
}
