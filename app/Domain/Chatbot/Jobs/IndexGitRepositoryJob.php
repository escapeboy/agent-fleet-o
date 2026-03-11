<?php

namespace App\Domain\Chatbot\Jobs;

use App\Domain\Chatbot\Enums\KnowledgeSourceStatus;
use App\Domain\Chatbot\Models\ChatbotKnowledgeSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;

class IndexGitRepositoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    // File extensions to index as code
    private const CODE_EXTENSIONS = ['php', 'js', 'ts', 'tsx', 'jsx', 'py', 'rb', 'go', 'rs', 'java', 'cs', 'cpp', 'c', 'h', 'swift', 'kt', 'md'];

    // Max files to index per repository
    private const MAX_FILES = 200;

    // Max file size in bytes to index (128 KB)
    private const MAX_FILE_SIZE = 131072;

    public function __construct(private readonly string $sourceId) {}

    public function handle(): void
    {
        $source = ChatbotKnowledgeSource::find($this->sourceId);

        if (! $source || $source->trashed()) {
            return;
        }

        $source->update(['status' => KnowledgeSourceStatus::Indexing]);

        $cloneDir = sys_get_temp_dir().'/chatbot-git-'.Str::random(12);

        try {
            $repoUrl = $source->source_url;
            $branch = $source->source_data['branch'] ?? 'main';

            if (! $repoUrl) {
                throw new \RuntimeException('Git repository URL is required.');
            }

            // Clone repository (shallow, single branch)
            $result = Process::run(
                "git clone --depth 1 --branch {$branch} --single-branch ".escapeshellarg($repoUrl)." ".escapeshellarg($cloneDir)
            );

            if (! $result->successful()) {
                throw new \RuntimeException("Git clone failed: {$result->errorOutput()}");
            }

            $chunks = $this->collectCodeChunks($cloneDir);

            // Delete existing chunks for this source (re-indexing)
            \App\Domain\Chatbot\Models\ChatbotKbChunk::where('source_id', $source->id)->delete();

            $totalChunks = 0;
            foreach (array_chunk($chunks, 20) as $batch) {
                $this->embedAndStore($source, $batch, $totalChunks);
                $totalChunks += count($batch);
            }

            $source->update([
                'status' => KnowledgeSourceStatus::Ready,
                'chunk_count' => $totalChunks,
                'indexed_at' => now(),
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('IndexGitRepositoryJob failed', [
                'source_id' => $source->id,
                'error' => $e->getMessage(),
            ]);
            $source->update([
                'status' => KnowledgeSourceStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
        } finally {
            // Clean up clone directory
            if (is_dir($cloneDir)) {
                Process::run("rm -rf ".escapeshellarg($cloneDir));
            }
        }
    }

    /**
     * Walk the cloned repository and collect code chunks from eligible files.
     */
    private function collectCodeChunks(string $dir): array
    {
        $chunks = [];
        $files = $this->listEligibleFiles($dir);

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            try {
                $size = filesize($file);

                if ($size === false || $size > self::MAX_FILE_SIZE || $size === 0) {
                    continue;
                }

                $content = file_get_contents($file);

                if ($content === false) {
                    continue;
                }

                $relativePath = ltrim(str_replace($dir, '', $file), '/');
                $fileChunks = $this->chunkCode($content, $relativePath);
                $chunks = array_merge($chunks, $fileChunks);
            } catch (\Throwable) {
                // Skip individual file failures
            }
        }

        return $chunks;
    }

    /**
     * List all eligible source files in the repository.
     */
    private function listEligibleFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            // Skip .git directory and hidden files
            if (str_contains($path, '/.git/') || str_contains($path, '/.')) {
                continue;
            }

            $ext = strtolower($file->getExtension());

            if (! in_array($ext, self::CODE_EXTENSIONS, true)) {
                continue;
            }

            $files[] = $path;
        }

        return $files;
    }

    /**
     * Split a code file into chunks, preserving file path context.
     */
    private function chunkCode(string $content, string $relativePath): array
    {
        $lines = explode("\n", $content);
        $chunkSize = 60; // lines per chunk
        $overlap = 10;
        $chunks = [];
        $i = 0;

        while ($i < count($lines)) {
            $slice = array_slice($lines, $i, $chunkSize);
            $chunkContent = implode("\n", $slice);

            $chunks[] = [
                'content' => $chunkContent,
                'metadata' => [
                    'file' => $relativePath,
                    'line_start' => $i + 1,
                    'line_end' => $i + count($slice),
                ],
            ];

            $i += ($chunkSize - $overlap);
        }

        return $chunks;
    }

    private function embedAndStore(ChatbotKnowledgeSource $source, array $chunks, int $startIndex): void
    {
        foreach ($chunks as $idx => $chunk) {
            $text = $chunk['content'];

            $response = Prism::embeddings()
                ->using('openai', 'text-embedding-3-small')
                ->fromInput($text)
                ->asEmbeddings();

            $vector = $response->embeddings[0]->embedding;
            $embeddingStr = '['.implode(',', $vector).']';

            DB::table('chatbot_kb_chunks')->insert([
                'id' => Str::orderedUuid(),
                'source_id' => $source->id,
                'chatbot_id' => $source->chatbot_id,
                'team_id' => $source->team_id,
                'content' => $text,
                'embedding' => DB::raw("'{$embeddingStr}'::vector"),
                'chunk_index' => $startIndex + $idx,
                'access_level' => 'code',
                'metadata' => json_encode($chunk['metadata'] ?? []),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
