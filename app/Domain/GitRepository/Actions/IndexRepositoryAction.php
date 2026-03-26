<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\Actions;

use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\CodeElement;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\GitRepository\Services\PhpCodeParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Indexes a GitRepository by iterating all PHP files via the GitClientInterface,
 * parsing each file for classes/methods/functions, and upserting CodeElement records.
 *
 * Change detection is hash-based: files whose content hasn't changed are skipped.
 * Full-text search vectors (tsvector) are updated in-place after each element insert.
 * Embedding generation is deferred — left null for a later phase.
 */
class IndexRepositoryAction
{
    public function __construct(
        private readonly PhpCodeParser $phpParser,
        private readonly GitOperationRouter $router,
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

            // Update the full-text search vector on PostgreSQL.
            // Silently skip on SQLite (tests) where tsvector is not available.
            try {
                DB::statement(
                    "UPDATE code_elements SET search_vector = to_tsvector('english', ?) WHERE id = ?",
                    [$text, $element->id],
                );
            } catch (\Throwable) {
                // SQLite or non-PostgreSQL environment — tsvector not supported.
            }
        }
    }
}
