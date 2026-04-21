<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\BugReportProjectConfig;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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

        $safeTitle = $this->sanitize($title, 120);
        $safeProject = $this->sanitize($projectKey, 100);
        $safeDescription = $this->sanitize($payload['description'] ?? '', 2000);
        $safeUrl = $this->sanitize($payload['url'] ?? '', 300);
        $safeSeverity = $this->sanitize($payload['severity'] ?? 'unknown', 30);

        // Build AI-structured block when StructureBugReportJob has already run
        $aiMetadata = $signal->metadata['ai_extracted'] ?? [];
        $structuredBlock = [];

        if (! empty($aiMetadata['steps_to_reproduce'])) {
            $structuredBlock[] = "**Steps to reproduce (AI-extracted):**\n"
                .$this->sanitize((string) $aiMetadata['steps_to_reproduce'], 1500);
        }
        if (! empty($aiMetadata['affected_user'])) {
            $structuredBlock[] = '**Affected user:** '.$this->sanitize((string) $aiMetadata['affected_user'], 100);
        }
        if (! empty($aiMetadata['component'])) {
            $structuredBlock[] = '**Component:** '.$this->sanitize((string) $aiMetadata['component'], 100);
        }
        if (! empty($signal->metadata['ai_tags'])) {
            $structuredBlock[] = '**Tags:** '.implode(', ', array_map(
                fn ($t) => $this->sanitize((string) $t, 30),
                $signal->metadata['ai_tags'],
            ));
        }

        $commentBlock = $this->buildCommentBlock($signal);
        if ($commentBlock !== null) {
            $structuredBlock[] = $commentBlock;
        }

        $thesis = implode("\n\n", array_filter([
            "## Bug Report: {$safeTitle}",
            "**Project:** {$safeProject}",
            "**Description:**\n{$safeDescription}",
            "**Page URL:** {$safeUrl}",
            "**Severity:** {$safeSeverity}",
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
            ...$structuredBlock,
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
            $type = $this->sanitize($error['type'] ?? '', 80);
            $message = $this->sanitize($error['message'] ?? '', 300);
            $lines[] = "- **{$type}**: {$message}";

            if (! empty($error['firstProjectFrame'])) {
                $f = $error['firstProjectFrame'];
                $file = $this->sanitize($f['file'] ?? '', 200);
                $line = (int) ($f['line'] ?? 0);
                $function = $this->sanitize($f['function'] ?? '', 100);
                $lines[] = "  First project frame: `{$file}:{$line}` in `{$function}`";
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

    private function sanitize(string $text, int $maxLen): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/u', '', mb_substr($text, 0, $maxLen)) ?? '';
    }

    /**
     * Render reporter/agent/support comments (and widget-visible human notes)
     * into a thesis section so the delegated agent can see rejected fix
     * attempts, updated repro steps, and team context.
     *
     * Why: without this the agent starts each delegation from scratch and
     * can re-attempt fixes the reporter has already explicitly rejected.
     */
    private function buildCommentBlock(Signal $signal): ?string
    {
        $cap = 20;
        $perCommentCap = 500;

        $query = $signal->comments()
            ->where(function (Builder $q) {
                $q->whereIn('author_type', ['reporter', 'agent', 'support'])
                    ->orWhere(function (Builder $q2) {
                        $q2->where('author_type', 'human')
                            ->where('widget_visible', true);
                    });
            });

        $total = (clone $query)->count();
        if ($total === 0) {
            return null;
        }

        /** @var \Illuminate\Support\Collection<int, SignalComment> $comments */
        $comments = $query->orderBy('created_at')->limit($cap)->get();

        $lines = [];
        foreach ($comments as $c) {
            $body = $this->stripInvisibleChars($this->sanitize((string) $c->body, $perCommentCap));
            $body = $this->escapeMarkdownHeaders($body);
            if ($body === '') {
                continue;
            }

            $date = optional($c->created_at)->format('Y-m-d') ?? '????-??-??';
            $author = $this->sanitize((string) $c->author_type, 20);
            $lines[] = "[{$date} {$author}] {$body}";
        }

        if ($lines === []) {
            return null;
        }

        $block = "**Reporter Feedback & Team Notes ({$total}):**\n".implode("\n\n", $lines);

        $overflow = $total - count($lines);
        if ($overflow > 0) {
            $block .= "\n\n... and {$overflow} older comments omitted";
        }

        return $block;
    }

    /**
     * Strip invisible Unicode that external reporters can use to smuggle
     * prompt-injection payloads past a visual review:
     *   - U+200B–U+200F (zero-width & directional marks)
     *   - U+202A–U+202E (bidi overrides)
     *   - U+2060–U+206F (word joiners & directional isolates)
     *   - U+FEFF         (byte-order mark / zero-width no-break space)
     *   - U+E0000–U+E007F (tag characters)
     */
    private function stripInvisibleChars(string $text): string
    {
        return preg_replace(
            '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]|[\x{E0000}-\x{E007F}]/u',
            '',
            $text,
        ) ?? '';
    }

    /**
     * Neutralize line-starting `**Header**` patterns inside untrusted comment
     * bodies so a reporter cannot impersonate a top-level thesis section
     * (e.g. a fake `**Agent Instructions:**` header telling the agent to
     * ignore the real thesis).
     */
    private function escapeMarkdownHeaders(string $text): string
    {
        return preg_replace('/^(\s*)(\*\*[A-Za-z])/m', '$1\\\\$2', $text) ?? '';
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
