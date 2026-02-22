<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Actions\InstallFromMarketplaceAction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class MarketplaceClient
{
    private string $registryUrl;

    private ?string $apiKey;

    private int $timeout;

    public function __construct()
    {
        $this->registryUrl = rtrim(config('marketplace.registry_url'), '/');
        $this->apiKey = config('marketplace.api_key');
        $this->timeout = config('marketplace.timeout', 15);
    }

    public function isEnabled(): bool
    {
        return config('marketplace.enabled', true) && ! empty($this->registryUrl);
    }

    /**
     * Browse marketplace listings with optional filters.
     */
    public function browse(array $filters = []): array
    {
        $response = $this->get('/listings', $filters);

        return $response;
    }

    /**
     * Get a single listing by slug.
     */
    public function show(string $slug): array
    {
        return $this->get("/listings/{$slug}");
    }

    /**
     * Download a listing's configuration for installation.
     */
    public function download(string $slug): array
    {
        return $this->get("/listings/{$slug}/download");
    }

    /**
     * Get available categories with counts.
     */
    public function categories(): array
    {
        return $this->get('/categories');
    }

    /**
     * Get reviews for a listing.
     */
    public function reviews(string $slug): array
    {
        return $this->get("/listings/{$slug}/reviews");
    }

    /**
     * Download and install a listing into the local instance.
     */
    public function install(string $slug, string $teamId, string $userId): mixed
    {
        $manifest = $this->download($slug);

        $expectedChecksum = $manifest['data']['checksum'] ?? null;
        $actualChecksum = hash('sha256', json_encode($manifest['data']['configuration'] ?? []));

        if ($expectedChecksum && $expectedChecksum !== $actualChecksum) {
            throw new \RuntimeException('Marketplace listing checksum mismatch. The download may be corrupted.');
        }

        return app(InstallFromMarketplaceAction::class)->executeFromManifest(
            type: $manifest['data']['type'],
            configuration: $manifest['data']['configuration'] ?? [],
            name: $manifest['data']['name'],
            version: $manifest['data']['version'] ?? '1.0.0',
            teamId: $teamId,
            userId: $userId,
        );
    }

    private function get(string $path, array $query = []): array
    {
        $request = Http::timeout($this->timeout)
            ->acceptJson();

        if ($this->apiKey) {
            $request = $request->withToken($this->apiKey);
        }

        try {
            $response = $request->get($this->registryUrl.$path, $query);
            $response->throw();

            return $response->json();
        } catch (ConnectionException $e) {
            throw new \RuntimeException("Cannot connect to marketplace registry at {$this->registryUrl}: {$e->getMessage()}");
        } catch (RequestException $e) {
            throw new \RuntimeException("Marketplace registry returned error: {$e->response->status()} {$e->response->body()}");
        }
    }
}
