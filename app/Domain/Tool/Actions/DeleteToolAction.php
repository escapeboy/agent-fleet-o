<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\Models\Tool;

class DeleteToolAction
{
    public function execute(Tool $tool): void
    {
        if ($tool->isPlatformTool()) {
            throw new \RuntimeException('Platform tools cannot be deleted by teams.');
        }

        // Detach from all agents before soft-deleting
        $tool->agents()->detach();
        $tool->delete();
    }
}
