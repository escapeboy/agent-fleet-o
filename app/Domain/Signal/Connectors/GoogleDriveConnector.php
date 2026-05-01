<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Knowledge\Actions\IngestKnowledgeDocumentAction;
use App\Domain\Signal\Contracts\KnowledgeConnectorInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class GoogleDriveConnector implements KnowledgeConnectorInterface
{
    private const REDIS_KEY_PREFIX = 'knowledge_sync:';

    private const DRIVE_FILES_ENDPOINT = 'https://www.googleapis.com/drive/v3/files';

    private const DRIVE_EXPORT_ENDPOINT = 'https://www.googleapis.com/drive/v3/files/%s/export';

    private const DRIVE_DOWNLOAD_ENDPOINT = 'https://www.googleapis.com/drive/v3/files/%s?alt=media';

    /** MIME types that can be exported as plain text */
    private const EXPORTABLE_TYPES = [
        'application/vnd.google-apps.document' => 'text/plain',
        'application/vnd.google-apps.spreadsheet' => 'text/csv',
        'application/vnd.google-apps.presentation' => 'text/plain',
    ];

    /** MIME types that can be downloaded directly as text */
    private const DOWNLOADABLE_TYPES = [
        'text/plain',
        'text/markdown',
        'text/csv',
        'application/json',
    ];

    public function __construct(
        private readonly IngestKnowledgeDocumentAction $ingestAction,
    ) {}

    public function getDriverName(): string
    {
        return 'google_drive';
    }

    public function supports(string $driver): bool
    {
        return $driver === 'google_drive';
    }

    public function isKnowledgeConnector(): bool
    {
        return true;
    }

    public function getLastSyncAt(string $bindingId): ?Carbon
    {
        $value = Redis::get(self::REDIS_KEY_PREFIX.$bindingId);

        return $value ? Carbon::parse($value) : null;
    }

    public function setLastSyncAt(string $bindingId, Carbon $at): void
    {
        Redis::setex(self::REDIS_KEY_PREFIX.$bindingId, 60 * 60 * 24 * 90, $at->toIso8601String());
    }

    /**
     * Poll Google Drive for recently modified files and ingest as Memory entries.
     *
     * Config keys:
     *   - access_token: OAuth2 bearer token (from Credential system)
     *   - folder_ids: comma-separated Drive folder IDs (leave empty to search all My Drive)
     *   - team_id: owning team
     *   - binding_id: connector binding UUID (for lastSyncAt tracking)
     *   - include_shared: true to include shared-with-me files
     *
     * @return array Always empty — knowledge connectors write to Memory directly.
     */
    public function poll(array $config): array
    {
        $accessToken = $config['access_token'] ?? null;
        $teamId = $config['team_id'] ?? null;
        $bindingId = $config['binding_id'] ?? 'google_drive_default';
        $folderIds = array_filter(array_map('trim', explode(',', $config['folder_ids'] ?? '')));
        $includeShared = filter_var($config['include_shared'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $accessToken || ! $teamId) {
            Log::warning('GoogleDriveConnector: Missing access_token or team_id', ['binding_id' => $bindingId]);

            return [];
        }

        $lastSync = $this->getLastSyncAt($bindingId) ?? now()->subDays(7);
        $syncTime = now();
        $ingested = 0;

        try {
            if ($folderIds !== []) {
                foreach ($folderIds as $folderId) {
                    $ingested += $this->pollFolder($folderId, $accessToken, $teamId, $lastSync);
                }
            } else {
                $ingested += $this->pollAllFiles($accessToken, $teamId, $lastSync, $includeShared);
            }
        } catch (\Throwable $e) {
            Log::error('GoogleDriveConnector: Poll error', [
                'binding_id' => $bindingId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->setLastSyncAt($bindingId, $syncTime);

        Log::info('GoogleDriveConnector: Sync complete', [
            'binding_id' => $bindingId,
            'ingested' => $ingested,
        ]);

        return [];
    }

    private function pollFolder(string $folderId, string $accessToken, string $teamId, Carbon $lastSync): int
    {
        return $this->fetchFiles(
            $accessToken,
            $teamId,
            $lastSync,
            "'{$folderId}' in parents and trashed = false",
        );
    }

    private function pollAllFiles(string $accessToken, string $teamId, Carbon $lastSync, bool $includeShared): int
    {
        $base = "trashed = false and modifiedTime > '{$lastSync->toRfc3339String()}'";
        $query = $includeShared ? $base : $base.' and \'me\' in owners';

        return $this->fetchFiles($accessToken, $teamId, $lastSync, $query);
    }

    private function fetchFiles(string $accessToken, string $teamId, Carbon $lastSync, string $query): int
    {
        $ingested = 0;
        $pageToken = null;

        do {
            $params = [
                'q' => $query,
                'fields' => 'nextPageToken, files(id, name, mimeType, webViewLink, modifiedTime)',
                'pageSize' => 100,
                'orderBy' => 'modifiedTime desc',
            ];

            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = Http::timeout(30)
                ->withToken($accessToken)
                ->get(self::DRIVE_FILES_ENDPOINT, $params);

            if (! $response->successful()) {
                Log::warning('GoogleDriveConnector: Files list failed', ['status' => $response->status()]);
                break;
            }

            $data = $response->json();

            foreach ($data['files'] ?? [] as $file) {
                $modifiedAt = Carbon::parse($file['modifiedTime']);
                if ($modifiedAt->lte($lastSync)) {
                    continue;
                }

                $content = $this->fetchFileContent($file, $accessToken);
                if ($content === null || trim($content) === '') {
                    continue;
                }

                try {
                    $this->ingestAction->execute(
                        teamId: $teamId,
                        title: $file['name'],
                        content: $content,
                        sourceUrl: $file['webViewLink'] ?? "https://drive.google.com/file/d/{$file['id']}",
                        sourceName: 'google_drive',
                    );
                    $ingested++;
                } catch (\Throwable $e) {
                    Log::error('GoogleDriveConnector: Ingest failed', [
                        'file_id' => $file['id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken);

        return $ingested;
    }

    private function fetchFileContent(array $file, string $accessToken): ?string
    {
        $mimeType = $file['mimeType'] ?? '';

        // Google Docs/Sheets/Slides: export as plain text
        if (isset(self::EXPORTABLE_TYPES[$mimeType])) {
            $exportMime = self::EXPORTABLE_TYPES[$mimeType];
            $url = sprintf(self::DRIVE_EXPORT_ENDPOINT, $file['id']);

            $response = Http::timeout(30)
                ->withToken($accessToken)
                ->get($url, ['mimeType' => $exportMime]);

            return $response->successful() ? $response->body() : null;
        }

        // Plain text files: direct download
        if (in_array($mimeType, self::DOWNLOADABLE_TYPES, true)) {
            $url = sprintf(self::DRIVE_DOWNLOAD_ENDPOINT, $file['id']);

            $response = Http::timeout(30)
                ->withToken($accessToken)
                ->get($url);

            return $response->successful() ? $response->body() : null;
        }

        return null;
    }
}
