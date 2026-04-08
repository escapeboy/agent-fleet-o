<?php

namespace App\Domain\Approval\Enums;

enum ApprovalMode: string
{
    /**
     * Workflow BLOCKS until explicit human approve/reject (existing behaviour).
     */
    case InLoop = 'in_loop';

    /**
     * Workflow CONTINUES automatically after the intervention window expires.
     * Human can still intervene and override during the window.
     */
    case OnLoop = 'on_loop';
}
