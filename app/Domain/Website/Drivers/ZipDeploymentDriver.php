<?php

namespace App\Domain\Website\Drivers;

use App\Domain\Website\Contracts\WebsiteDeploymentDriver;
use App\Domain\Website\DTOs\DeploymentResult;
use App\Domain\Website\Enums\DeploymentProvider;
use App\Domain\Website\Exceptions\DeploymentDriverException;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDeployment;
use App\Domain\Website\Services\WebsiteZipBuilder;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class ZipDeploymentDriver implements WebsiteDeploymentDriver
{
    public function __construct(
        private readonly WebsiteZipBuilder $builder,
    ) {}

    public function provider(): DeploymentProvider
    {
        return DeploymentProvider::Zip;
    }

    public function deploy(Website $website, WebsiteDeployment $deployment): DeploymentResult
    {
        $tmpPath = $this->builder->build($website);

        if (! file_exists($tmpPath)) {
            throw new DeploymentDriverException('WebsiteZipBuilder did not produce an archive at: '.$tmpPath);
        }

        $bytes = filesize($tmpPath) ?: 0;
        $targetRelative = 'website-deployments/'.$website->id.'/'.$deployment->id.'.zip';

        $disk = Storage::disk('local');
        $disk->putFileAs(
            'website-deployments/'.$website->id,
            new File($tmpPath),
            $deployment->id.'.zip',
        );

        @unlink($tmpPath);

        $signedUrl = URL::temporarySignedRoute(
            'websites.deployment.download',
            now()->addDays(7),
            ['deployment' => $deployment->id],
        );

        $logMessage = sprintf(
            'ZIP archive built at %s (%d bytes). Download link valid for 7 days.',
            $targetRelative,
            $bytes,
        );

        return DeploymentResult::success(
            url: $signedUrl,
            logMessage: $logMessage,
            providerMetadata: [
                'storage_disk' => 'local',
                'storage_path' => $targetRelative,
                'bytes' => $bytes,
            ],
        );
    }
}
