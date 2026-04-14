<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use App\Models\User;

class DelegateBugReportToAgentAction
{
    public function __construct(
        private readonly CreateExperimentAction $createExperiment,
        private readonly UpdateSignalStatusAction $updateStatus,
    ) {}

    public function execute(Signal $signal, User $actor, ?string $agentId = null): Experiment
    {
        $payload = $signal->payload ?? [];
        $title = $payload['title'] ?? 'Bug report';
        $projectKey = $signal->project_key ?? ($payload['project'] ?? 'unknown');

        // Build console errors extract (most useful for agent)
        $consoleLog = $payload['console_log'] ?? [];
        $consoleErrors = array_values(array_filter(
            $consoleLog,
            fn ($entry) => in_array($entry['level'] ?? '', ['error', 'warn'], true),
        ));

        $screenshotUrl = $signal->getFirstMediaUrl('bug_report_files');

        $thesis = implode("\n\n", array_filter([
            "## Bug Report: {$title}",
            "**Project:** {$projectKey}",
            "**Description:**\n".($payload['description'] ?? ''),
            "**Page URL:** ".($payload['url'] ?? ''),
            "**Severity:** ".($payload['severity'] ?? 'unknown'),
            $screenshotUrl ? "**Screenshot:** {$screenshotUrl}" : null,
            ! empty($consoleErrors)
                ? "**Console Errors:**\n".json_encode($consoleErrors, JSON_PRETTY_PRINT)
                : null,
            ! empty($payload['action_log'])
                ? "**Reproduction Steps (last 30 actions):**\n".json_encode($payload['action_log'], JSON_PRETTY_PRINT)
                : null,
        ]));

        $experiment = $this->createExperiment->execute(
            userId: $actor->id,
            title: "Fix bug: {$title}",
            thesis: $thesis,
            track: 'agentic',
            teamId: $signal->team_id,
            agentId: $agentId,
        );

        // Link signal to experiment and set status
        $signal->update(['experiment_id' => $experiment->id]);

        $this->updateStatus->execute(
            signal: $signal,
            newStatus: SignalStatus::DelegatedToAgent,
            comment: "Delegated to agent (experiment: {$experiment->id})",
            actor: $actor,
        );

        // Auto-advance experiment to Scoring
        $experiment->update(['status' => ExperimentStatus::Scoring]);

        return $experiment;
    }
}
