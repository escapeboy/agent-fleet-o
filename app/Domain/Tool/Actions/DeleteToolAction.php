<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\Models\Tool;

class DeleteToolAction
{
    public function execute(Tool $tool): void
    {
        // Detach from all agents before soft-deleting
        $tool->agents()->detach();
        $tool->delete();
    }
}
