<?php

namespace App\Domain\Website\Contracts;

use App\Domain\Website\DTOs\DeploymentResult;
use App\Domain\Website\Enums\DeploymentProvider;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDeployment;

interface WebsiteDeploymentDriver
{
    public function provider(): DeploymentProvider;

    public function deploy(Website $website, WebsiteDeployment $deployment): DeploymentResult;
}
