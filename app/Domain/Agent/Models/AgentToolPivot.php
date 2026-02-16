<?php

namespace App\Domain\Agent\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class AgentToolPivot extends Pivot
{
    protected $table = 'agent_tool';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'overrides' => 'array',
        ];
    }
}
