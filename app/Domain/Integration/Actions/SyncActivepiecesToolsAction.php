<?php

namespace App\Domain\Integration\Actions;

use App\Domain\Integration\DTOs\ActivepiecesSyncResult;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Services\SsrfGuard;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fetches the piece list from a connected Activepieces instance,
 * upserts each piece as a Tool (type=mcp_http), and deactivates
 * any previously-synced tools whose pieces are no longer present.
 *
 * Results are cached for 5 minutes to absorb repeated calls.
 */
class SyncActivepiecesToolsAction
{
    public function __construct(
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    /**
     * Execute the sync.
     *
     * @throws \RuntimeException When the Activepieces API call fails.
     */
    public function execute(Integration $integration): ActivepiecesSyncResult
    {
        $teamId = (string) $integration->getAttribute('team_id');
        $integrationId = (string) $integration->getKey();

        // 5-minute cache to avoid hammering the Activepieces instance on
        // repeated requests (e.g. multiple webhook or UI triggers).
        $cacheKey = "activepieces_sync_{$integrationId}";

        if (Cache::has($cacheKey)) {
            /** @var ActivepiecesSyncResult $cached */
            $cached = Cache::get($cacheKey);

            return $cached;
        }

        /** @var array<string, mixed> $integrationConfig */
        $integrationConfig = $integration->config;
        $baseUrl = rtrim((string) ($integration->getCredentialSecret('base_url') ?? $integrationConfig['base_url'] ?? ''), '/');
        $apiKey = (string) ($integration->getCredentialSecret('api_key') ?? '');

        if (! $baseUrl) {
            throw new \RuntimeException('Activepieces base_url is not configured.');
        }

        // SSRF protection — block requests to private/loopback addresses.
        $this->ssrfGuard->assertPublicUrl($baseUrl);

        $response = Http::withToken($apiKey)
            ->timeout(15)
            ->get("{$baseUrl}/api/v1/pieces", ['release' => 'latest', 'includeHidden' => 'false']);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Activepieces pieces API returned HTTP {$response->status()}: ".$response->body(),
            );
        }

        /** @var array<int, array<string, mixed>> $pieces */
        $pieces = $response->json() ?? [];

        if (empty($pieces)) {
            Cache::put($cacheKey, ActivepiecesSyncResult::empty(), now()->addMinutes(5));

            return ActivepiecesSyncResult::empty();
        }

        $upsertedCount = 0;
        $syncedSlugs = [];

        foreach ($pieces as $piece) {
            $pieceName = (string) ($piece['name'] ?? '');
            $displayName = (string) ($piece['displayName'] ?? $pieceName);

            if (! $pieceName) {
                continue;
            }

            // Stable, unique slug per team + piece.
            $slug = Str::slug("ap-{$pieceName}");
            $syncedSlugs[] = $slug;

            $existing = Tool::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('slug', $slug)
                ->withTrashed()
                ->first();

            $toolData = [
                'team_id' => $teamId,
                'name' => $displayName,
                'slug' => $slug,
                'description' => (string) ($piece['description'] ?? "Activepieces piece: {$displayName}"),
                'type' => ToolType::McpHttp,
                'status' => ToolStatus::Active,
                'transport_config' => [
                    'url' => "{$baseUrl}/api/mcp/{$pieceName}",
                ],
                'credentials' => [
                    'api_key' => $apiKey,
                    'base_url' => $baseUrl,
                ],
                'settings' => [
                    'activepieces_piece_name' => $pieceName,
                    'activepieces_integration_id' => $integrationId,
                    'last_synced_at' => now()->toIso8601String(),
                ],
            ];

            if ($existing) {
                // Restore if soft-deleted, then update.
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->update($toolData);
            } else {
                Tool::withoutGlobalScopes()->create($toolData);
            }

            $upsertedCount++;
        }

        // Disable previously-synced pieces that are no longer returned by the API.
        $deactivatedCount = Tool::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereRaw("settings->>'activepieces_integration_id' = ?", [$integrationId])
            ->where('status', ToolStatus::Active)
            ->whereNotIn('slug', $syncedSlugs)
            ->update(['status' => ToolStatus::Disabled]);

        $result = new ActivepiecesSyncResult(
            upserted: $upsertedCount,
            deactivated: $deactivatedCount,
            message: "Synced {$upsertedCount} pieces, deactivated {$deactivatedCount} stale tools.",
        );

        Cache::put($cacheKey, $result, now()->addMinutes(5));

        return $result;
    }
}
