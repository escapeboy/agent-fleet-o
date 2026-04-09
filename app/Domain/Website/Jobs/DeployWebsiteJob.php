<?php

namespace App\Domain\Website\Jobs;

use App\Domain\Website\Drivers\WebsiteDeploymentDriverRegistry;
use App\Domain\Website\Enums\DeploymentStatus;
use App\Domain\Website\Models\WebsiteDeployment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeployWebsiteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public string $deploymentId,
    ) {}

    public function handle(WebsiteDeploymentDriverRegistry $registry): void
    {
        $deployment = WebsiteDeployment::query()->find($this->deploymentId);

        if (! $deployment || $deployment->status->isTerminal()) {
            return;
        }

        $website = $deployment->website;

        if (! $website) {
            $deployment->update([
                'status' => DeploymentStatus::Failed,
                'build_log' => 'Website not found.',
            ]);

            return;
        }

        $deployment->update([
            'status' => DeploymentStatus::Building,
            'started_at' => now(),
        ]);

        try {
            $driver = $registry->resolve($deployment->provider);
            $result = $driver->deploy($website, $deployment);

            $deployment->update([
                'status' => $result->status,
                'url' => $result->url,
                'build_log' => $result->logMessage,
                'config' => array_merge($deployment->config ?? [], $result->providerMetadata),
                'deployed_at' => $result->status === DeploymentStatus::Deployed ? now() : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Website deployment failed', [
                'deployment_id' => $deployment->id,
                'website_id' => $deployment->website_id,
                'provider' => $deployment->provider->value,
                'error' => $e->getMessage(),
            ]);

            $deployment->update([
                'status' => DeploymentStatus::Failed,
                'build_log' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
