<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\DTOs\CommentGuardrailResult;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\Log;

/**
 * Inspects the *added* comment lines in a unified diff and asks an LLM judge
 * whether each one earns its place (explains a non-obvious WHY) or is
 * low-value (restates the code, decorative banner, commented-out code).
 *
 * Borrowed from oh-my-opencode's `comment-checker` hook, recast as an
 * agent-side guardrail: the agent passes its own working-tree diff and uses
 * the findings to clean up before opening a pull request. Advisory only —
 * never blocks. Fails open when the judge is unreachable or unparsable.
 */
class InspectDiffCommentsAction
{
    /**
     * Comment-marker sets keyed by language family. A line (with its leading
     * '+' stripped and left-trimmed) is a comment candidate when it starts
     * with one of these markers.
     *
     * @var array<string, list<string>>
     */
    private const MARKERS = [
        'c' => ['//', '/*', '*/', '* '],
        'hash' => ['#'],
        'html' => ['<!--'],
        'dash' => ['--'],
    ];

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    public function execute(string $diff, ?Team $team = null, ?string $userId = null): CommentGuardrailResult
    {
        $comments = $this->extractAddedComments($diff);

        if ($comments === []) {
            return CommentGuardrailResult::clean(0, 'No added comments to review.');
        }

        $resolved = $this->providerResolver->resolve(team: $team, purpose: 'guardrail');
        $provider = $resolved['provider'] ?? 'anthropic';
        $model = $resolved['model'] ?? 'claude-haiku-4-5';

        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: $this->systemPrompt(),
                userPrompt: $this->userPrompt($comments),
                maxTokens: 2048,
                userId: $userId,
                teamId: $team?->id,
                purpose: 'guardrail.comments',
            ));
        } catch (\Throwable $e) {
            Log::warning('InspectDiffCommentsAction: judge gateway failed, failing open', [
                'team_id' => $team?->id,
                'added_comments' => count($comments),
                'error' => $e->getMessage(),
            ]);

            return CommentGuardrailResult::skipped('Comment judge unreachable: '.$e->getMessage(), count($comments));
        }

        $parsed = is_array($response->parsedOutput) ? $response->parsedOutput : $this->extractJson($response->content);

        if (! is_array($parsed) || ! isset($parsed['flagged']) || ! is_array($parsed['flagged'])) {
            Log::warning('InspectDiffCommentsAction: judge output unparsable, failing open', [
                'team_id' => $team?->id,
            ]);

            return CommentGuardrailResult::skipped('Comment judge output was not valid JSON.', count($comments));
        }

        $flagged = [];
        foreach ($parsed['flagged'] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $flagged[] = [
                'file' => (string) ($item['file'] ?? ''),
                'line' => (int) ($item['line'] ?? 0),
                'comment' => (string) ($item['comment'] ?? ''),
                'reason' => (string) ($item['reason'] ?? ''),
            ];
        }

        $summary = isset($parsed['summary']) && is_string($parsed['summary'])
            ? $parsed['summary']
            : sprintf('%d of %d added comment(s) flagged as low-value.', count($flagged), count($comments));

        return new CommentGuardrailResult(
            judged: true,
            flagged: $flagged,
            addedComments: count($comments),
            summary: $summary,
        );
    }

    /**
     * Parse a unified diff and return the comment lines it ADDS, with the
     * destination file path and new-file line number for each.
     *
     * @return array<int, array{file: string, line: int, comment: string, code: string}>
     */
    public function extractAddedComments(string $diff): array
    {
        $found = [];
        $currentFile = '';
        $newLineNo = 0;

        foreach (preg_split('/\r\n|\r|\n/', $diff) ?: [] as $line) {
            if (str_starts_with($line, '+++ ')) {
                $currentFile = $this->parseFilePath(substr($line, 4));

                continue;
            }
            if (str_starts_with($line, '--- ') || str_starts_with($line, 'diff ') || str_starts_with($line, 'index ')) {
                continue;
            }
            if (str_starts_with($line, '@@')) {
                if (preg_match('/@@ -\d+(?:,\d+)? \+(\d+)/', $line, $m) === 1) {
                    $newLineNo = (int) $m[1];
                }

                continue;
            }

            // Removed lines do not advance the new-file line counter.
            if (str_starts_with($line, '-')) {
                continue;
            }

            // Added line: candidate for inspection, then advance the counter.
            if (str_starts_with($line, '+')) {
                $content = substr($line, 1);
                $comment = $this->commentText($currentFile, $content);
                if ($comment !== null && $currentFile !== '') {
                    $found[] = [
                        'file' => $currentFile,
                        'line' => $newLineNo,
                        'comment' => $comment,
                        'code' => trim($content),
                    ];
                }
                $newLineNo++;

                continue;
            }

            // "\ No newline at end of file" and other markers do not advance.
            if (str_starts_with($line, '\\')) {
                continue;
            }

            // Context line (leading space or blank) advances the new-file counter.
            $newLineNo++;
        }

        return $found;
    }

    /**
     * Returns the trimmed comment text if the added content is a comment for
     * the file's language, otherwise null.
     */
    private function commentText(string $file, string $content): ?string
    {
        $trimmed = ltrim($content);
        if ($trimmed === '') {
            return null;
        }

        foreach ($this->markersFor($file) as $marker) {
            if (str_starts_with($trimmed, $marker)) {
                return trim($content);
            }
        }

        // Block-comment continuation lines that are exactly '*'.
        if ($trimmed === '*' && in_array('* ', $this->markersFor($file), true)) {
            return trim($content);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function markersFor(string $file): array
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return match (true) {
            $ext === 'php' => [...self::MARKERS['c'], ...self::MARKERS['hash']],
            in_array($ext, ['js', 'jsx', 'ts', 'tsx', 'java', 'c', 'cc', 'cpp', 'cxx', 'h', 'hpp', 'hh', 'go', 'rs', 'swift', 'kt', 'kts', 'scala', 'm', 'mm', 'dart', 'scss', 'less'], true) => self::MARKERS['c'],
            $ext === 'css' => ['/*', '*/', '* '],
            in_array($ext, ['py', 'rb', 'sh', 'bash', 'zsh', 'yaml', 'yml', 'toml', 'ini', 'cfg', 'conf', 'pl', 'pm', 'r', 'ex', 'exs', 'nim'], true) => self::MARKERS['hash'],
            in_array($ext, ['html', 'htm', 'xml', 'vue', 'svelte', 'md', 'markdown'], true) => self::MARKERS['html'],
            in_array($ext, ['sql', 'lua', 'hs', 'elm'], true) => [...self::MARKERS['dash'], '/*', '*/'],
            default => [...self::MARKERS['c'], ...self::MARKERS['hash']],
        };
    }

    private function parseFilePath(string $raw): string
    {
        $raw = trim($raw);
        // Strip a trailing tab + timestamp some diff tools append.
        $raw = preg_split('/\t/', $raw)[0] ?? $raw;
        if ($raw === '/dev/null') {
            return '';
        }
        if (str_starts_with($raw, 'b/') || str_starts_with($raw, 'a/')) {
            $raw = substr($raw, 2);
        }

        return $raw;
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You are a strict code reviewer enforcing comment discipline. You are given comments that a diff ADDS.

        A comment EARNS its place only if it explains a non-obvious WHY: a hidden constraint, a subtle invariant,
        a workaround for a specific bug, a reference to an external issue, or a security/concurrency caveat.

        FLAG a comment when it: restates what the adjacent code already says; is a decorative banner or section
        divider; is commented-out code; is an obvious/redundant label; or is a leftover TODO with no actionable detail.

        Do NOT flag docblocks/parameter docs that a public API genuinely needs, or comments that record a real WHY.

        Reply ONLY with JSON, no prose:
        {"flagged":[{"file":"<path>","line":<int>,"comment":"<text>","reason":"<short why it is low-value>"}],"summary":"<one line>"}
        If nothing should be flagged, return {"flagged":[],"summary":"<one line>"}.
        TXT;
    }

    /**
     * @param  array<int, array{file: string, line: int, comment: string, code: string}>  $comments
     */
    private function userPrompt(array $comments): string
    {
        $lines = ['Review these added comments:', ''];
        foreach ($comments as $c) {
            $lines[] = sprintf('File: %s (line %d)', $c['file'], $c['line']);
            $lines[] = 'Comment: '.mb_substr($c['comment'], 0, 300);
            if ($c['code'] !== '' && $c['code'] !== $c['comment']) {
                $lines[] = 'Code context: '.mb_substr($c['code'], 0, 200);
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $content): ?array
    {
        $stripped = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($content)) ?? '');

        $decoded = json_decode($stripped, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Balanced-brace scan: local agents emit fences and duplicate chunks.
        $start = strpos($stripped, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $length = strlen($stripped);
        for ($i = $start; $i < $length; $i++) {
            $ch = $stripped[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($stripped, $start, $i - $start + 1);
                    $decoded = json_decode($candidate, true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }
}
