<?php

namespace App\Domain\GitRepository\Actions;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;

/**
 * Generate a Conventional Commits message for a git mutation, using a "weak"
 * (cheap) model. Aider-inspired (build #2, Trendshift top-5 sprint).
 *
 * Falls back to a templated message when:
 *   - the gateway throws (rate limit, no API key, etc.),
 *   - the LLM returns an empty / malformed string,
 *   - the change has no actual content to summarize.
 *
 * The weak model is haiku by design — message generation should never become
 * expensive enough to gate the actual git op.
 */
class GenerateCommitMessageAction
{
    /** @var array<int, string> */
    private const ALLOWED_TYPES = [
        'feat', 'fix', 'chore', 'docs', 'test', 'refactor', 'perf', 'build', 'ci', 'style',
    ];

    public function __construct(private readonly AiGatewayInterface $gateway) {}

    /**
     * @param  list<string>  $paths   Paths affected by the mutation
     * @param  string        $kind    'write' | 'delete' | 'patch' | 'commit_batch'
     * @param  string        $contentSample  Up to ~3500 chars of content/diff (will be truncated)
     * @param  string        $teamId  Resolved team id (for budget / userId fallback)
     * @param  string|null   $original Optional caller-supplied message — kept as fallback if generation fails.
     */
    public function execute(
        array $paths,
        string $kind,
        string $contentSample,
        string $teamId,
        ?string $original = null,
    ): string {
        $sample = mb_substr($contentSample, 0, 3500);
        $shownPaths = array_slice($paths, 0, 10);

        if ($sample === '' && empty($paths)) {
            return $this->fallbackMessage($paths, $kind, $original);
        }

        try {
            $prompt = $this->buildPrompt($shownPaths, $sample, $kind);

            $userId = Team::ownerIdFor($teamId);

            $request = new AiRequestDTO(
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                systemPrompt: 'You are a strict Conventional Commits writer. Output ONLY the commit message line, nothing else.',
                userPrompt: $prompt,
                maxTokens: 80,
                userId: $userId,
                teamId: $teamId,
                purpose: 'commit_message',
                idempotencyKey: 'commit-msg:'.hash('xxh3', $sample.'|'.implode(',', $shownPaths).'|'.$kind),
                temperature: 0.0,
            );

            $response = $this->gateway->complete($request);
            $candidate = $this->sanitize((string) ($response->content ?? ''));

            if ($candidate !== '') {
                return $candidate;
            }
        } catch (\Throwable $e) {
            Log::warning('GenerateCommitMessageAction: weak-model generation failed; using fallback', [
                'team_id' => $teamId,
                'paths_count' => count($paths),
                'error' => $e->getMessage(),
            ]);
        }

        return $this->fallbackMessage($paths, $kind, $original);
    }

    /**
     * @param  list<string>  $paths
     */
    private function buildPrompt(array $paths, string $sample, string $kind): string
    {
        $lines = [
            'You are writing exactly ONE Conventional Commits commit message for the change shown below.',
            '',
            'Rules:',
            '- ONE LINE only, under 72 characters.',
            '- Type prefix MUST be one of: feat, fix, chore, docs, test, refactor, perf, build, ci, style.',
            '- Imperative mood; lowercase letter after the colon ("fix: handle empty foo", not "Fix: Handle Empty Foo").',
            '- No trailing period.',
            '- No emojis. No issue numbers. No "by Claude" / "via AI" / similar.',
            '- Output ONLY the commit message line — no preamble, no explanation, no quotes.',
            '',
            "Operation: {$kind}",
            'Files changed:',
        ];
        foreach ($paths as $p) {
            $lines[] = "- {$p}";
        }
        if ($sample !== '') {
            $lines[] = '';
            $lines[] = 'Content / diff snippet (truncated):';
            $lines[] = '```';
            $lines[] = $sample;
            $lines[] = '```';
        }
        $lines[] = '';
        $lines[] = 'Commit message:';

        return implode("\n", $lines);
    }

    /**
     * Coerce the LLM output into a single Conventional-Commits-shaped line.
     */
    private function sanitize(string $raw): string
    {
        $first = trim((string) strtok($raw, "\n"));
        if ($first === '') {
            return '';
        }

        // Strip trailing period.
        $first = rtrim($first, " \t.");

        // If the first line doesn't already match the convention, prepend a default type.
        $allowed = implode('|', self::ALLOWED_TYPES);
        if (! preg_match('/^('.$allowed.')(\([^)]+\))?: \S/', $first)) {
            // If the model produced something like "Add foo" or "added foo", coerce to "chore: ".
            $first = 'chore: '.lcfirst(ltrim($first));
        }

        return mb_substr($first, 0, 72);
    }

    /**
     * @param  list<string>  $paths
     */
    private function fallbackMessage(array $paths, string $kind, ?string $original): string
    {
        // If caller already supplied something Conventional-shaped, keep it.
        if ($original !== null && $original !== '') {
            $sanitized = $this->sanitize($original);
            if ($sanitized !== '') {
                return $sanitized;
            }
        }

        $verb = match ($kind) {
            'delete' => 'remove',
            'write' => 'update',
            'patch' => 'patch',
            'commit_batch' => 'update',
            default => 'update',
        };

        $type = $kind === 'delete' ? 'chore' : 'chore';
        $first = $paths[0] ?? 'files';

        return mb_substr("{$type}: {$verb} {$first}", 0, 72);
    }
}
