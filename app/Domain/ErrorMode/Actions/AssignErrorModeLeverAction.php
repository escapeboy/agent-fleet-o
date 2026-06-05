<?php

namespace App\Domain\ErrorMode\Actions;

use App\Domain\ErrorMode\Enums\ErrorModeLever;
use App\Domain\ErrorMode\Enums\ErrorModeStatus;
use App\Domain\ErrorMode\Models\ErrorMode;

/**
 * Assign a remediation lever (and optionally a status) to an error mode.
 * This is the human triage step that turns the catalog into an engineering plan.
 */
final class AssignErrorModeLeverAction
{
    public function execute(
        string $teamId,
        string $errorModeId,
        ErrorModeLever $lever,
        ?ErrorModeStatus $status = null,
    ): ErrorMode {
        $mode = ErrorMode::query()
            ->where('team_id', $teamId)
            ->findOrFail($errorModeId);

        $mode->lever = $lever;
        if ($status !== null) {
            $mode->status = $status;
        }
        $mode->save();

        return $mode;
    }
}
