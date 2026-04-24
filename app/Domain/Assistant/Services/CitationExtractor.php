<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Memory\Models\Memory;
use App\Domain\Project\Models\Project;
use App\Domain\Signal\Models\Signal;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class CitationExtractor
{
    /**
     * Map of citation kind → [Eloquent model FQN, named route, title column (null = custom)].
     *
     * @var array<string, array{0: class-string<Model>, 1: string, 2: ?string}>
     */
    private const KIND_MAP = [
        'experiment' => [Experiment::class, 'experiments.show', 'title'],
        'project' => [Project::class, 'projects.show', 'title'],
        'agent' => [Agent::class, 'agents.show', 'name'],
        'workflow' => [Workflow::class, 'workflows.show', 'name'],
        'crew' => [Crew::class, 'crews.show', 'name'],
        'skill' => [Skill::class, 'skills.show', 'name'],
        'signal' => [Signal::class, 'signals.index', null],
        'memory' => [Memory::class, 'memory.index', null],
    ];

    private const UUID_REGEX = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * Extract inline citation markers, validate against tool results, resolve titles.
     *
     * Scans $text for `[[kind:uuid]]` markers. Any marker whose uuid did not appear
     * in $toolResults is silently stripped (anti-hallucination defence). Surviving
     * markers are replaced with sequential footnote refs `[N](url)` in markdown form.
     *
     * @param  array<int, array<string, mixed>>  $toolResults
     * @return array{text: string, citations: list<array{n: int, kind: string, id: string, title: string, url: string}>}
     */
    public function extract(string $text, array $toolResults): array
    {
        if ($text === '' || ! str_contains($text, '[[')) {
            return ['text' => $text, 'citations' => []];
        }

        $whitelist = $this->collectWhitelistedIds($toolResults);

        $pattern = '/\[\[([a-z_]+):('.self::UUID_REGEX.')\]\]/i';
        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return ['text' => $text, 'citations' => []];
        }

        $surviving = [];
        $seen = [];
        $n = 0;

        foreach ($matches as $match) {
            $raw = $match[0][0];
            $kind = strtolower($match[1][0]);
            $id = strtolower($match[2][0]);
            $offset = $match[0][1];

            if (! isset(self::KIND_MAP[$kind])) {
                Log::debug('CitationExtractor: unknown kind dropped', ['kind' => $kind, 'id' => $id]);

                continue;
            }
            if (! isset($whitelist[$id])) {
                Log::debug('CitationExtractor: hallucinated id dropped', ['kind' => $kind, 'id' => $id]);

                continue;
            }

            $dedupKey = $kind.':'.$id;
            if (! isset($seen[$dedupKey])) {
                $n++;
                $seen[$dedupKey] = $n;
            }

            $surviving[] = [
                'raw' => $raw,
                'offset' => $offset,
                'length' => strlen($raw),
                'n' => $seen[$dedupKey],
                'kind' => $kind,
                'id' => $id,
            ];
        }

        if ($surviving === []) {
            $cleanText = $this->stripAllMarkers($text, $matches);

            return ['text' => $cleanText, 'citations' => []];
        }

        $resolved = $this->resolveTitles($surviving);

        $rewritten = $this->rewriteText($text, $matches, $resolved);

        $citations = [];
        foreach ($resolved as $key => $data) {
            if ($key === '_urls_by_ref') {
                continue;
            }
            $citations[] = $data;
        }
        usort($citations, fn ($a, $b) => $a['n'] <=> $b['n']);

        return ['text' => $rewritten, 'citations' => $citations];
    }

    /**
     * Recursively walk tool results and collect every uuid-shaped string value.
     * We don't partition by kind — most tool results don't declare the kind,
     * and the marker itself carries that info.
     *
     * @param  array<int|string, mixed>  $payload
     * @return array<string, true>
     */
    private function collectWhitelistedIds(array $payload): array
    {
        $found = [];
        $this->walkForUuids($payload, $found);

        return $found;
    }

    /**
     * @param  mixed  $node
     * @param  array<string, true>  $found
     */
    private function walkForUuids($node, array &$found): void
    {
        if (is_string($node)) {
            if (preg_match('/^'.self::UUID_REGEX.'$/i', $node)) {
                $found[strtolower($node)] = true;
            }

            return;
        }
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $value) {
            $this->walkForUuids($value, $found);
        }
    }

    /**
     * Bulk-resolve titles and build URLs. Groups by kind → one query per kind.
     *
     * @param  list<array{raw: string, offset: int, length: int, n: int, kind: string, id: string}>  $surviving
     * @return array<string, array{n: int, kind: string, id: string, title: string, url: string}>
     *                                                                                              Keyed by "kind:id" so callers can look up by citation ref.
     */
    private function resolveTitles(array $surviving): array
    {
        $idsByKind = [];
        foreach ($surviving as $s) {
            $idsByKind[$s['kind']][$s['id']] = true;
        }

        $titles = [];
        foreach ($idsByKind as $kind => $ids) {
            [$modelClass, $routeName, $titleCol] = self::KIND_MAP[$kind];
            $idList = array_keys($ids);
            $cols = ['id'];
            if ($titleCol !== null) {
                $cols[] = $titleCol;
            }
            if ($kind === 'signal') {
                $cols[] = 'source_type';
            }
            if ($kind === 'memory') {
                $cols[] = 'content';
            }

            try {
                $rows = $modelClass::query()->whereIn('id', $idList)->get($cols);
            } catch (\Throwable $e) {
                Log::warning('CitationExtractor: title lookup failed', [
                    'kind' => $kind,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($rows as $row) {
                $titles[$kind.':'.strtolower($row->id)] = $this->buildDisplayTitle($kind, $row, $titleCol);
            }
        }

        $result = [];
        $numberedSeen = [];
        foreach ($surviving as $s) {
            $key = $s['kind'].':'.$s['id'];
            if (isset($numberedSeen[$key])) {
                continue;
            }
            $numberedSeen[$key] = true;

            $title = $titles[$key] ?? null;
            if ($title === null) {
                Log::debug('CitationExtractor: entity not found, dropping citation', [
                    'kind' => $s['kind'],
                    'id' => $s['id'],
                ]);

                continue;
            }

            $url = $this->buildUrl($s['kind'], $s['id']);
            if ($url === null) {
                continue;
            }

            $result[$key] = [
                'n' => $s['n'],
                'kind' => $s['kind'],
                'id' => $s['id'],
                'title' => $title,
                'url' => $url,
            ];
        }

        return $result;
    }

    private function buildDisplayTitle(string $kind, Model $row, ?string $titleCol): string
    {
        if ($titleCol !== null) {
            $value = $row->getAttribute($titleCol);
            if (is_string($value) && $value !== '') {
                return mb_strimwidth($value, 0, 80, '…');
            }
        }
        if ($kind === 'signal') {
            $source = $row->getAttribute('source_type') ?? 'signal';

            return sprintf('%s · %s', ucfirst((string) $source), substr((string) $row->id, 0, 8));
        }
        if ($kind === 'memory') {
            $content = $row->getAttribute('content');
            if (is_string($content) && $content !== '') {
                return mb_strimwidth(trim($content), 0, 60, '…');
            }

            return 'Memory · '.substr((string) $row->id, 0, 8);
        }

        return ucfirst($kind).' · '.substr((string) $row->id, 0, 8);
    }

    private function buildUrl(string $kind, string $id): ?string
    {
        [, $routeName] = self::KIND_MAP[$kind];

        try {
            if ($kind === 'signal' || $kind === 'memory') {
                return route($routeName, ['highlight' => $id]);
            }

            return route($routeName, [$this->routeParamFor($kind) => $id]);
        } catch (\Throwable $e) {
            Log::warning('CitationExtractor: route build failed', [
                'kind' => $kind,
                'route' => $routeName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function routeParamFor(string $kind): string
    {
        return match ($kind) {
            'experiment' => 'experiment',
            'project' => 'project',
            'agent' => 'agent',
            'workflow' => 'workflow',
            'crew' => 'crew',
            'skill' => 'skill',
            default => 'id',
        };
    }

    /**
     * Replace every survived marker with a markdown footnote ref `[N](url)`.
     * Walk markers right-to-left so earlier offsets stay valid.
     *
     * @param  list<array<int, array{0: string, 1: int}>>  $matches  preg_match_all PREG_OFFSET_CAPTURE payload
     * @param  array<string, array{n: int, kind: string, id: string, title: string, url: string}>  $resolved
     */
    private function rewriteText(string $text, array $matches, array $resolved): string
    {
        $ops = [];
        foreach ($matches as $match) {
            $raw = $match[0][0];
            $offset = $match[0][1];
            $kind = strtolower($match[1][0]);
            $id = strtolower($match[2][0]);
            $key = $kind.':'.$id;

            $replacement = isset($resolved[$key])
                ? sprintf(' [[%d]](%s)', $resolved[$key]['n'], $resolved[$key]['url'])
                : '';

            $ops[] = [$offset, strlen($raw), $replacement];
        }

        usort($ops, fn ($a, $b) => $b[0] <=> $a[0]);

        foreach ($ops as [$offset, $length, $replacement]) {
            $text = substr_replace($text, $replacement, $offset, $length);
        }

        return $this->cleanupWhitespace($text);
    }

    /**
     * No survivors — strip every marker so raw JSON-looking `[[...]]` never
     * leaks to the user.
     *
     * @param  list<array<int, array{0: string, 1: int}>>  $matches
     */
    private function stripAllMarkers(string $text, array $matches): string
    {
        $ops = [];
        foreach ($matches as $match) {
            $ops[] = [$match[0][1], strlen($match[0][0])];
        }
        usort($ops, fn ($a, $b) => $b[0] <=> $a[0]);

        foreach ($ops as [$offset, $length]) {
            $text = substr_replace($text, '', $offset, $length);
        }

        return $this->cleanupWhitespace($text);
    }

    private function cleanupWhitespace(string $text): string
    {
        // Collapse " , " or "  ." artifacts left when markers were stripped or
        // replaced at punctuation boundaries. Keep the impact minimal — only
        // fix the most common cases.
        $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;

        return $text;
    }
}
