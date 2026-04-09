<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\DeploymentProvider;
use App\Domain\Website\Enums\DeploymentStatus;
use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Exceptions\DeploymentDriverException;
use App\Domain\Website\Jobs\DeployWebsiteJob;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDeployment;

class DeployWebsiteAction
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function execute(
        Website $website,
        DeploymentProvider $provider,
        array $config = [],
    ): WebsiteDeployment {
        $publishedPages = $website->pages()
            ->where('status', WebsitePageStatus::Published)
            ->whereNotNull('exported_html')
            ->count();

        if ($publishedPages === 0) {
            throw new DeploymentDriverException(
                'Website has no published pages with exported HTML. Publish at least one page before deploying.',
            );
        }

        $deployment = WebsiteDeployment::create([
            'website_id' => $website->id,
            'team_id' => $website->team_id,
            'provider' => $provider,
            'status' => DeploymentStatus::Queued,
            'config' => $config,
        ]);

        DeployWebsiteJob::dispatch($deployment->id)->onQueue('experiments');

        return $deployment;
    }
}
