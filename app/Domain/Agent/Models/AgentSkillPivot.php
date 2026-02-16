<?php

namespace App\Domain\Agent\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class AgentSkillPivot extends Pivot
{
    protected $table = 'agent_skill';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'overrides' => 'array',
        ];
    }
}
