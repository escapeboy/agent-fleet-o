<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services\PageHelp;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;

/**
 * Dynamic page-help for experiments.show:
 *   - failed → diagnostic guidance with explicit pointers to the new
 *              "Diagnose" surface (P0 self-service troubleshooting arc)
 *   - paused → resume guidance
 *   - awaiting_approval → approval guidance
 *   - default (active or completed) → falls back to static help
 */
final class ExperimentDetailHelpResolver
{
    /**
     * @param  array<string, mixed>  $routeParameters
     * @return array<string, mixed>|null
     */
    public function __invoke(array $routeParameters): ?array
    {
        $experiment = $routeParameters['experiment'] ?? null;
        if (! $experiment instanceof Experiment) {
            return null;
        }

        $status = $experiment->status;

        if ($status->isFailed()) {
            return [
                'description' => 'This experiment failed. The "Diagnose" card at the top translates the technical error into a customer-readable explanation and surfaces 1-click recovery options.',
                'steps' => [
                    'Click the orange "Diagnose" button at the top of the page',
                    'Review the suggested root cause and recommended actions',
                    'Apply the suggested action (retry, switch provider, top up credits) — most are 1 click',
                    'If the error is novel, click "Ask assistant to investigate"',
                ],
                'tips' => [
                    'Most failures fall into ~14 known patterns (rate-limit, budget, schema, etc.) and have an inline fix',
                    'The Execution Log + Time Travel tabs are still available for deep inspection',
                ],
            ];
        }

        if ($status === ExperimentStatus::Paused) {
            return [
                'description' => 'This experiment is paused. It will not progress until you resume or kill it.',
                'steps' => [
                    'Review the reason it was paused (often visible at the top of the page or in the Transitions tab)',
                    'If paused for budget, top up credits in Billing first',
                    'Click Resume to continue from where it left off',
                ],
                'tips' => [
                    'Auto-pause from PauseOnBudgetExceeded means the team or project hit its budget cap',
                    'Resume keeps the same workflow / playbook step — no progress is lost',
                ],
            ];
        }

        if ($status === ExperimentStatus::AwaitingApproval) {
            return [
                'description' => 'This experiment is waiting for human approval before continuing.',
                'steps' => [
                    'Review the proposed plan in the current stage panel',
                    'Open the Approvals page to approve or reject with a reason',
                    'Approved plans resume automatically; rejected plans return to Planning',
                ],
            ];
        }

        return null;
    }
}
