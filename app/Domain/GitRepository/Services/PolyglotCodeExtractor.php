<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\Services;

use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\DTOs\ExtractedEdge;
use App\Domain\GitRepository\DTOs\ExtractedElement;
use App\Domain\GitRepository\DTOs\ExtractionResult;
use App\Domain\GitRepository\Models\GitRepository;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Polyglot symbol/edge extractor backed by CodeGraph's MIT tree-sitter CLI.
 *
 * NON-PHP only: PHP files are owned by PhpCodeParser (CodeGraph mis-binds PHP
 * builtins like empty()/collect() to false call edges — see
 * docs/research/research_codegraph_2026-05-27.md §4b). This pass materializes
 * non-PHP source into a temp dir, runs `codegraph index` over it, and maps the
 * resulting SQLite graph into FleetQ's code_elements / code_edges shape.
 *
 * Graceful no-op when the binary is absent or the feature flag is off, so the
 * code is safe to ship before the image carries the binary.
 */
class PolyglotCodeExtractor
{
    /** CodeGraph node kinds that become code_elements, mapped to our element_type. */
    private const ELEMENT_KIND_MAP = [
        'class' => 'class',
        'interface' => 'class',
        'trait' => 'class',
        'enum' => 'class',
        'struct' => 'class',
        'function' => 'function',
        'method' => 'method',
    ];

    /** CodeGraph edge kinds that become code_edges, mapped to our edge_type. */
    private const EDGE_KIND_MAP = [
        'calls' => 'calls',
        'instantiates' => 'calls',
        'imports' => 'imports',
        'extends' => 'inherits',
        'implements' => 'inherits',
    ];

    public function isEnabled(): bool
    {
        return (bool) config('git_repository.polyglot_index', false) && $this->binaryAvailable();
    }

    public function binaryAvailable(): bool
    {
        $bin = (string) config('git_repository.codegraph_bin', 'codegraph');
        $which = new Process(['which', $bin]);
        $which->run();

        return $which->isSuccessful();
    }

    /**
     * Run a full non-PHP extraction pass for the repository. Returns an empty
     * result (never throws for "nothing to do") when disabled, when the binary
     * is missing, or when the repo has no non-PHP source.
     */
    public function extract(GitRepository $repository, GitClientInterface $client): ExtractionResult
    {
        if (! $this->isEnabled()) {
            return new ExtractionResult;
        }

        $workdir = $this->makeTempDir();

        try {
            $materialized = $this->materialize($repository, $client, $workdir);
            if ($materialized === 0) {
                return new ExtractionResult;
            }

            $this->runCodegraph($workdir);

            $dbPath = $workdir.'/.codegraph/codegraph.db';
            if (! is_file($dbPath)) {
                Log::warning('PolyglotCodeExtractor: codegraph produced no database', [
                    'repository_id' => $repository->id,
                ]);

                return new ExtractionResult;
            }

            return $this->mapDatabase($dbPath);
        } finally {
            $this->removeDir($workdir);
        }
    }

    /**
     * Read a CodeGraph SQLite index and normalize it into FleetQ's element/edge
     * shape. Pure (no side effects); the unit-testable core of the extractor.
     */
    public function mapDatabase(string $dbPath): ExtractionResult
    {
        $pdo = new \PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        /** @var array<string, ExtractedElement> $elements keyed by CodeGraph node id */
        $elements = [];

        $nodeStmt = $pdo->query(
            'SELECT id, kind, name, file_path, language, start_line, end_line, signature, docstring FROM nodes',
        );
        foreach ($nodeStmt as $row) {
            $elementType = self::ELEMENT_KIND_MAP[$row['kind']] ?? null;
            if ($elementType === null) {
                continue; // import / variable / field / route / file → skip
            }
            if (($row['language'] ?? '') === 'php') {
                continue; // PHP is owned by PhpCodeParser; defensive even if materialization excluded it
            }

            $elements[$row['id']] = new ExtractedElement(
                graphId: (string) $row['id'],
                elementType: $elementType,
                name: (string) $row['name'],
                filePath: (string) $row['file_path'],
                lineStart: $row['start_line'] !== null ? (int) $row['start_line'] : null,
                lineEnd: $row['end_line'] !== null ? (int) $row['end_line'] : null,
                signature: $this->nullableString($row['signature'] ?? null),
                docstring: $this->nullableString($row['docstring'] ?? null),
                language: (string) ($row['language'] ?? 'unknown'),
            );
        }

        $edges = [];
        $edgeStmt = $pdo->query('SELECT source, target, kind FROM edges');
        foreach ($edgeStmt as $row) {
            $edgeType = self::EDGE_KIND_MAP[$row['kind']] ?? null;
            if ($edgeType === null) {
                continue; // contains / references → skip
            }
            // Both endpoints must be elements we kept; drops edges to imports/files.
            if (! isset($elements[$row['source']], $elements[$row['target']])) {
                continue;
            }

            $edges[] = new ExtractedEdge(
                sourceGraphId: (string) $row['source'],
                targetGraphId: (string) $row['target'],
                edgeType: $edgeType,
            );
        }

        return new ExtractionResult(array_values($elements), $edges);
    }

    /**
     * Write non-PHP source files from the git client into $workdir, preserving
     * relative paths. Returns the number of files written (capped).
     */
    private function materialize(GitRepository $repository, GitClientInterface $client, string $workdir): int
    {
        /** @var list<string> $extensions */
        $extensions = (array) config('git_repository.polyglot_extensions', []);
        $maxFiles = (int) config('git_repository.polyglot_max_files', 5000);
        $maxBytes = (int) config('git_repository.polyglot_max_file_bytes', 1048576);

        $tree = $client->getFileTree();
        $written = 0;

        foreach ($tree as $entry) {
            if ($written >= $maxFiles) {
                break;
            }
            if ($entry['type'] !== 'blob') {
                continue;
            }
            $path = $entry['path'];
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === '' || ! in_array($ext, $extensions, true)) {
                continue;
            }

            // Path-traversal guard: tree paths come from an external repo and are
            // untrusted. Reject absolute paths, null bytes, and any `.`/`..` segment
            // so a crafted entry like `../../etc/cron.d/x` can't escape $workdir.
            if (! $this->isSafeRelativePath($path)) {
                Log::warning('PolyglotCodeExtractor: rejected unsafe tree path', ['path' => $path]);

                continue;
            }

            try {
                $content = $client->readFile($path);
            } catch (\Throwable $e) {
                Log::debug('PolyglotCodeExtractor: skipped unreadable file', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (strlen($content) > $maxBytes) {
                continue;
            }

            $target = $workdir.'/'.$path;
            $dir = dirname($target);
            if (! is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }

            // Defence in depth: confirm the resolved directory is still inside
            // $workdir before writing (catches symlink / edge cases the string
            // check above could miss).
            $realDir = realpath($dir);
            $realRoot = realpath($workdir);
            if ($realDir === false || $realRoot === false || ! str_starts_with($realDir.'/', $realRoot.'/')) {
                Log::warning('PolyglotCodeExtractor: resolved path escaped workdir', ['path' => $path]);

                continue;
            }

            file_put_contents($target, $content);
            $written++;
        }

        return $written;
    }

    private function runCodegraph(string $workdir): void
    {
        $bin = (string) config('git_repository.codegraph_bin', 'codegraph');
        $timeout = (int) config('git_repository.codegraph_timeout', 180);

        // init creates .codegraph/; index builds the graph. Two deterministic,
        // non-interactive calls (the interactive flow is `codegraph install`).
        $init = new Process([$bin, 'init', $workdir], timeout: $timeout);
        $init->run();

        $index = new Process([$bin, 'index', $workdir, '--quiet'], timeout: $timeout);
        $index->run();

        if (! $index->isSuccessful()) {
            Log::warning('PolyglotCodeExtractor: codegraph index exited non-zero', [
                'exit_code' => $index->getExitCode(),
                'stderr' => mb_substr($index->getErrorOutput(), 0, 500),
            ]);
        }
    }

    /**
     * Whether a repo-supplied relative path is safe to materialize under $workdir:
     * no null byte, not absolute, and no `.`/`..` path segment.
     */
    public function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return false;
        }

        return preg_match('#(^|/)\.\.?(/|$)#', $path) !== 1;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/codegraph_'.bin2hex(random_bytes(8));
        mkdir($dir, 0o755, true);

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $rm = new Process(['rm', '-rf', $dir]);
        $rm->run();
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string) $value);

        return $str === '' ? null : $str;
    }
}
