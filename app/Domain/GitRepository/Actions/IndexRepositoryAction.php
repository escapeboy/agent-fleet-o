<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\Actions;

use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\CodeEdge;
use App\Domain\GitRepository\Models\CodeElement;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\GitRepository\Services\PhpCodeParser;
use App\Domain\GitRepository\Services\PolyglotCodeExtractor;
use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Indexes a GitRepository by iterating all PHP files via the GitClientInterface,
 * parsing each file for classes/methods/functions, and upserting CodeElement records.
 *
 * Change detection is hash-based: files whose content hasn't changed are skipped.
 * Full-text search vectors (tsvector) are updated in-place after each element insert.
 * Each element is also embedded (BYOK via EmbeddingService) for semantic code
 * search; teams without an embedding key degrade gracefully to keyword-only.
 */
class IndexRepositoryAction
{
    public function __construct(
        private readonly PhpCodeParser $phpParser,
        private readonly GitOperationRouter $router,
        private readonly EmbeddingProviderInterface $embeddings,
        private readonly PolyglotCodeExtractor $polyglot,
    ) {}

    public function execute(GitRepository $repository): void
    {
        $repository->update(['indexing_status' => 'indexing']);

        try {
            $this->indexRepository($repository);

            $repository->update([
                'indexing_status' => 'indexed',
                'last_indexed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $repository->update(['indexing_status' => 'failed']);
            throw $e;
        }
    }

    private function indexRepository(GitRepository $repository): void
    {
        $client = $this->router->resolve($repository);

        // Fetch the full file tree from the remote (GitHub/GitLab API or Bridge).
        $tree = $client->getFileTree();

        // Filter to PHP source files only.
        $phpFiles = array_filter($tree, fn (array $entry): bool => (
            ($entry['type'] ?? '') === 'blob'
            && str_ends_with($entry['path'] ?? '', '.php')
        ));

        foreach ($phpFiles as $entry) {
            $path = $entry['path'];

            try {
                $this->indexFile($repository, $client, $path);
            } catch (\Throwable $e) {
                // Log per-file failures but continue indexing other files.
                Log::warning('IndexRepositoryAction: failed to index file', [
                    'repository_id' => $repository->id,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Second pass: non-PHP source via the CodeGraph extractor. A no-op when
        // the feature flag is off or the binary is absent. Failures here must
        // not fail the (successful) PHP index.
        try {
            $this->indexPolyglot($repository, $client);
        } catch (\Throwable $e) {
            Log::warning('IndexRepositoryAction: polyglot pass failed', [
                'repository_id' => $repository->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Index non-PHP source files using the CodeGraph extractor, populating both
     * code_elements and code_edges. Whole-tree re-extract: all existing non-PHP
     * elements for the repo are replaced (their edges cascade-delete via FK).
     */
    private function indexPolyglot(GitRepository $repository, GitClientInterface $client): void
    {
        $result = $this->polyglot->extract($repository, $client);

        if ($result->isEmpty()) {
            return;
        }

        // Replace prior non-PHP elements (PHP elements own the .php files and are
        // left untouched). Cascade FK clears the associated code_edges rows.
        CodeElement::where('git_repository_id', $repository->id)
            ->where('file_path', 'not like', '%.php')
            ->delete();

        // Insert elements, mapping CodeGraph node ids → our UUIDs for edge resolution.
        $idMap = [];
        foreach ($result->elements as $extracted) {
            $text = implode("\n", array_filter([
                $extracted->elementType.': '.$extracted->name,
                $extracted->signature ? 'Signature: '.$extracted->signature : null,
                $extracted->docstring ? 'Docstring: '.$extracted->docstring : null,
            ]));

            $element = CodeElement::create([
                'team_id' => $repository->team_id,
                'git_repository_id' => $repository->id,
                'element_type' => $extracted->elementType,
                'name' => $extracted->name,
                'file_path' => $extracted->filePath,
                'line_start' => $extracted->lineStart,
                'line_end' => $extracted->lineEnd,
                'signature' => $extracted->signature,
                'docstring' => $extracted->docstring,
                'content_hash' => null,
                'indexed_at' => now(),
            ]);

            $idMap[$extracted->graphId] = $element->id;

            $this->applySearchAndEmbedding($element->id, $text, $repository->team_id);
        }

        // Insert edges, resolving both endpoints through the id map.
        foreach ($result->edges as $edge) {
            $sourceId = $idMap[$edge->sourceGraphId] ?? null;
            $targetId = $idMap[$edge->targetGraphId] ?? null;
            if ($sourceId === null || $targetId === null) {
                continue;
            }

            CodeEdge::create([
                'team_id' => $repository->team_id,
                'git_repository_id' => $repository->id,
                'source_id' => $sourceId,
                'target_id' => $targetId,
                'edge_type' => $edge->edgeType,
            ]);
        }
    }

    private function indexFile(
        GitRepository $repository,
        GitClientInterface $client,
        string $relativePath,
    ): void {
        $content = $client->readFile($relativePath);
        $fileHash = hash('sha256', $content);

        // Skip unchanged files using a sentinel "file" element that stores the file hash.
        $sentinel = CodeElement::where('git_repository_id', $repository->id)
            ->where('file_path', $relativePath)
            ->where('element_type', 'file')
            ->first();

        if ($sentinel && $sentinel->content_hash === $fileHash) {
            return;
        }

        // Delete all previous elements for this file (sentinel + parsed elements).
        CodeElement::where('git_repository_id', $repository->id)
            ->where('file_path', $relativePath)
            ->delete();

        // Upsert a sentinel "file" element so we can detect changes on next index.
        CodeElement::create([
            'team_id' => $repository->team_id,
            'git_repository_id' => $repository->id,
            'element_type' => 'file',
            'name' => basename($relativePath),
            'file_path' => $relativePath,
            'line_start' => null,
            'line_end' => null,
            'signature' => null,
            'docstring' => null,
            'content_hash' => $fileHash,
            'indexed_at' => now(),
        ]);

        // Parse the PHP file for code elements.
        $elements = $this->phpParser->parseFile($relativePath, $content);

        foreach ($elements as $elementData) {
            $text = implode("\n", array_filter([
                $elementData->elementType.': '.$elementData->name,
                $elementData->signature ? 'Signature: '.$elementData->signature : null,
                $elementData->docstring ? 'Docstring: '.$elementData->docstring : null,
            ]));

            $element = CodeElement::create([
                'team_id' => $repository->team_id,
                'git_repository_id' => $repository->id,
                'element_type' => $elementData->elementType,
                'name' => $elementData->name,
                'file_path' => $elementData->filePath,
                'line_start' => $elementData->lineStart,
                'line_end' => $elementData->lineEnd,
                'signature' => $elementData->signature,
                'docstring' => $elementData->docstring,
                'content_hash' => $elementData->contentHash,
                'indexed_at' => now(),
            ]);

            $this->applySearchAndEmbedding($element->id, $text, $repository->team_id);
        }
    }

    /**
     * Set the PostgreSQL full-text search vector and (best-effort) the pgvector
     * embedding for an element. Both are no-ops on SQLite / without a BYOK key —
     * indexing must never abort because search or embedding is unavailable.
     */
    private function applySearchAndEmbedding(string $elementId, string $text, string $teamId): void
    {
        try {
            DB::statement(
                "UPDATE code_elements SET search_vector = to_tsvector('english', ?) WHERE id = ?",
                [$text, $elementId],
            );
        } catch (\Throwable) {
            // SQLite or non-PostgreSQL environment — tsvector not supported.
        }

        try {
            $vector = $this->embeddings->embedForTeam($text, $teamId);
            if ($vector !== null) {
                DB::statement(
                    'UPDATE code_elements SET embedding = ? WHERE id = ?',
                    [$this->embeddings->formatForPgvector($vector), $elementId],
                );
            }
        } catch (\Throwable $e) {
            Log::debug('IndexRepositoryAction: embedding skipped', [
                'element_id' => $elementId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
