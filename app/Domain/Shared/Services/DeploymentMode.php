<?php

namespace App\Domain\Shared\Services;

final class DeploymentMode
{
    public function isCloud(): bool
    {
        // Check DEPLOYMENT_MODE first (new standard)
        if (config('app.deployment_mode') === 'cloud') {
            return true;
        }

        // Legacy support for CLOUD_MODE (used in cloud edition config)
        return (bool) config('cloud.mode', false);
    }

    public function isSelfHosted(): bool
    {
        return ! $this->isCloud();
    }
}
