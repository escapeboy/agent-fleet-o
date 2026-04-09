<?php

namespace App\Domain\Website\Drivers;

use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Models\Credential;
use App\Domain\Website\Contracts\WebsiteDeploymentDriver;
use App\Domain\Website\DTOs\DeploymentResult;
use App\Domain\Website\Enums\DeploymentProvider;
use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Exceptions\DeploymentDriverException;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDeployment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class VercelDeploymentDriver implements WebsiteDeploymentDriver
{
    private const API_BASE = 'https://api.vercel.com';

    private const CREDENTIAL_SLUG = 'vercel-token';

    public function provider(): DeploymentProvider
    {
        return DeploymentProvider::Vercel;
    }

    public function deploy(Website $website, WebsiteDeployment $deployment): DeploymentResult
    {
        $token = $this->resolveToken($website->team_id);

        $files = $this->buildFilesPayload($website);

        if ($files === []) {
            throw new DeploymentDriverException('No published pages with HTML to deploy.');
        }

        $projectName = $this->projectName($website);

        $response = Http::withToken($token)
            ->timeout(60)
            ->post(self::API_BASE.'/v13/deployments', [
                'name' => $projectName,
                'files' => $files,
                'target' => 'production',
                'projectSettings' => [
                    'framework' => null,
                ],
            ]);

        if ($response->failed()) {
            throw new DeploymentDriverException(
                'Vercel API error: '.$response->status().' '.$response->body(),
            );
        }

        $body = $response->json();
        $deploymentUrl = isset($body['url']) ? 'https://'.$body['url'] : null;

        return DeploymentResult::success(
            url: $deploymentUrl,
            logMessage: sprintf(
                'Vercel deployment created: id=%s state=%s',
                $body['id'] ?? 'unknown',
                $body['readyState'] ?? 'unknown',
            ),
            providerMetadata: [
                'vercel_deployment_id' => $body['id'] ?? null,
                'vercel_ready_state' => $body['readyState'] ?? null,
                'vercel_project_name' => $projectName,
            ],
        );
    }

    private function resolveToken(string $teamId): string
    {
        /** @var Credential|null $credential */
        $credential = Credential::query()
            ->where('team_id', $teamId)
            ->where('slug', self::CREDENTIAL_SLUG)
            ->where('status', CredentialStatus::Active)
            ->first();

        if (! $credential) {
            throw new DeploymentDriverException(
                'Vercel API token not configured. Create a credential with slug "'.self::CREDENTIAL_SLUG.'" on the Credentials page.',
            );
        }

        $secretData = $credential->secret_data ?? [];
        $token = $secretData['token'] ?? $secretData['api_key'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new DeploymentDriverException(
                'Vercel credential exists but "token" (or "api_key") field is missing or empty.',
            );
        }

        return $token;
    }

    /**
     * @return array<int, array{file: string, data: string}>
     */
    private function buildFilesPayload(Website $website): array
    {
        $pages = $website->pages()
            ->where('status', WebsitePageStatus::Published)
            ->whereNotNull('exported_html')
            ->orderBy('sort_order')
            ->get();

        $files = [];
        $indexAssigned = false;

        foreach ($pages as $page) {
            $html = $page->exported_html ?? '';

            if (! $indexAssigned && (in_array($page->slug, ['index', 'home'], true) || $pages->first()->is($page))) {
                $files[] = ['file' => 'index.html', 'data' => $html];
                $indexAssigned = true;

                continue;
            }

            $safeSlug = basename(Str::slug($page->slug));
            $files[] = ['file' => $safeSlug.'.html', 'data' => $html];
        }

        return $files;
    }

    private function projectName(Website $website): string
    {
        return Str::slug('fleetq-'.$website->slug, '-');
    }
}
