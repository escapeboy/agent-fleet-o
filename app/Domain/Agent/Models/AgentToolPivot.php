<?php

namespace App\Domain\Agent\Models;

use App\Domain\Tool\Enums\ApprovalTimeoutAction;
use App\Domain\Tool\Enums\ToolApprovalMode;
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
            'approval_mode' => ToolApprovalMode::class,
            'approval_timeout_minutes' => 'integer',
            'approval_timeout_action' => ApprovalTimeoutAction::class,
        ];
    }
}
