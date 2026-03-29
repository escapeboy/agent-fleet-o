<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Memory\Models\Memory;

class IngestKnowledgeDocumentAction
{
    private const MAX_CONTENT_LENGTH = 8000;

    /**
     * Create or update a Memory entry for an ingested knowledge document.
     *
     * Uses source_url as the unique key per team to upsert — re-ingesting the same
     * URL updates the content and metadata rather than creating duplicates.
     *
     * @param  string  $sourceName  e.g. 'notion', 'confluence', 'github_wiki'
     */
    public function execute(
        string $teamId,
        string $title,
        string $content,
        string $sourceUrl,
        string $sourceName,
    ): Memory {
        $content = mb_substr(trim($content), 0, self::MAX_CONTENT_LENGTH);

        $contentWithTitle = $title ? "[{$title}]\n\n{$content}" : $content;
        $contentWithTitle = mb_substr($contentWithTitle, 0, self::MAX_CONTENT_LENGTH);

        $contentHash = hash('sha256', mb_strtolower(trim($contentWithTitle)));

        /** @var Memory $memory */
        $memory = Memory::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('source_url', $sourceUrl)
            ->first();

        if ($memory) {
            $memory->update([
                'content' => $contentWithTitle,
                'content_hash' => $contentHash,
                'source_type' => $sourceName,
                'metadata' => array_merge($memory->metadata ?? [], [
                    'title' => $title,
                    'source_name' => $sourceName,
                    'source_url' => $sourceUrl,
                    'last_synced_at' => now()->toIso8601String(),
                ]),
            ]);
        } else {
            $memory = Memory::create([
                'team_id' => $teamId,
                'content' => $contentWithTitle,
                'content_hash' => $contentHash,
                'source_type' => $sourceName,
                'source_url' => $sourceUrl,
                'tags' => ['knowledge', $sourceName],
                'confidence' => 1.0,
                'importance' => 0.7,
                'metadata' => [
                    'title' => $title,
                    'source_name' => $sourceName,
                    'source_url' => $sourceUrl,
                    'last_synced_at' => now()->toIso8601String(),
                ],
            ]);
        }

        return $memory;
    }
}
