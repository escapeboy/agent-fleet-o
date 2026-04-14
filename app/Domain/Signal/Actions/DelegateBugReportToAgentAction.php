<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\BugReportProjectConfig;
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

        // Enriched data from async processing (may not be present on older reports)
        $resolvedErrors = $payload['resolved_errors'] ?? [];
        $suspectFiles = $payload['suspect_files'] ?? [];

        // Per-project agent instructions
        $projectConfig = BugReportProjectConfig::where('team_id', $signal->team_id)
            ->where('project', $projectKey)
            ->first();

        $agentInstructions = $projectConfig?->config ?? [];

        // Map suspect files to related test paths using naming conventions
        $relatedTests = $this->discoverRelatedTests($suspectFiles);
        if (! empty($relatedTests)) {
            $agentInstructions['related_tests'] = $relatedTests;
        }

        if (! empty($agentInstructions['test_command']) && ! empty($relatedTests)) {
            // Suggest running the most specific test file
            $agentInstructions['verification'] = 'After fixing, run: '.$agentInstructions['test_command'].'. All tests must pass.';
        }

        $thesis = implode("\n\n", array_filter([
            "## Bug Report: {$title}",
            "**Project:** {$projectKey}",
            "**Description:**\n".($payload['description'] ?? ''),
            "**Page URL:** ".($payload['url'] ?? ''),
            "**Severity:** ".($payload['severity'] ?? 'unknown'),
            $screenshotUrl ? "**Screenshot:** {$screenshotUrl}" : null,
            // Prefer resolved errors (with original source paths) over raw console errors
            ! empty($resolvedErrors)
                ? "**Resolved Errors (original source locations):**\n".$this->formatResolvedErrors($resolvedErrors)
                : (! empty($consoleErrors) ? "**Console Errors:**\n".json_encode($consoleErrors, JSON_PRETTY_PRINT) : null),
            ! empty($suspectFiles)
                ? "**Suspect Files (ranked by confidence):**\n".$this->formatSuspectFiles($suspectFiles)
                : null,
            ! empty($agentInstructions)
                ? "**Agent Instructions:**\n".json_encode($agentInstructions, JSON_PRETTY_PRINT)
                : null,
            ! empty($payload['action_log'])
                ? "**Reproduction Steps (last 30 actions):**\n".json_encode(array_slice((array) $payload['action_log'], -30), JSON_PRETTY_PRINT)
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

    /**
     * @param  array<int, array>  $resolvedErrors
     */
    private function formatResolvedErrors(array $resolvedErrors): string
    {
        $lines = [];

        foreach ($resolvedErrors as $error) {
            $lines[] = "- **{$error['type']}**: {$error['message']}";

            if (! empty($error['firstProjectFrame'])) {
                $f = $error['firstProjectFrame'];
                $lines[] = "  First project frame: `{$f['file']}:{$f['line']}` in `{$f['function']}`";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array>  $suspectFiles
     */
    private function formatSuspectFiles(array $suspectFiles): string
    {
        $lines = [];

        foreach ($suspectFiles as $file) {
            $confidence = number_format($file['confidence'] * 100).'%';
            $lines[] = "- `{$file['path']}` ({$confidence}) — {$file['reason']}";
        }

        return implode("\n", $lines);
    }

    /**
     * Map suspect file paths to likely test paths using naming conventions.
     *
     * @param  array<int, array>  $suspectFiles
     * @return string[]
     */
    private function discoverRelatedTests(array $suspectFiles): array
    {
        $tests = [];

        foreach ($suspectFiles as $file) {
            $path = $file['path'] ?? '';
            $test = $this->fileToTestPath($path);

            if ($test && ! in_array($test, $tests, true)) {
                $tests[] = $test;
            }
        }

        return array_values($tests);
    }

    private function fileToTestPath(string $path): ?string
    {
        // app/Livewire/Foo/Bar.php → tests/Feature/Livewire/Foo/BarTest.php
        if (str_starts_with($path, 'app/Livewire/')) {
            $relative = substr($path, strlen('app/Livewire/'));
            $testPath = 'tests/Feature/Livewire/'.str_replace('.php', 'Test.php', $relative);

            return $testPath;
        }

        // app/Http/Controllers/FooController.php → tests/Feature/Http/FooControllerTest.php
        if (str_starts_with($path, 'app/Http/Controllers/')) {
            $relative = substr($path, strlen('app/Http/Controllers/'));
            $testPath = 'tests/Feature/Http/'.str_replace('.php', 'Test.php', $relative);

            return $testPath;
        }

        // app/Services/FooService.php → tests/Unit/Services/FooServiceTest.php
        if (str_starts_with($path, 'app/Services/')) {
            $relative = substr($path, strlen('app/Services/'));
            $testPath = 'tests/Unit/Services/'.str_replace('.php', 'Test.php', $relative);

            return $testPath;
        }

        return null;
    }
}
